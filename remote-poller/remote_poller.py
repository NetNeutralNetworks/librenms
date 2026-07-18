#!/usr/bin/env python3
"""LibreNMS Remote Service Poller.

A lightweight agent that runs Nagios-compatible service checks (icmp, http,
db, ...) and serves the latest results over HTTPS with mutual TLS.

The main LibreNMS server periodically calls POST /api/v1/sync with the list
of checks assigned to this poller and receives the most recent cached
results in the same response. Between syncs, checks are executed locally on
their own schedule by a background scheduler thread, so results are always
ready when the main server fetches them.

Endpoints (all require a client certificate signed by the configured CA):
  GET  /api/v1/status   -> poller identity / health information
  GET  /api/v1/results  -> latest cached results for all configured checks
  POST /api/v1/sync     -> replace check config, returns latest results

Configuration is taken from environment variables, see README.md.
"""

import json
import os
import re
import shlex
import ssl
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VERSION = '1.0.0'

CONFIG = {
    'listen_addr': os.environ.get('RP_LISTEN_ADDR', '0.0.0.0'),
    'listen_port': int(os.environ.get('RP_PORT', '8443')),
    'poller_name': os.environ.get('RP_POLLER_NAME', os.uname().nodename),
    'tls_cert': os.environ.get('RP_TLS_CERT', '/certs/server.crt'),
    'tls_key': os.environ.get('RP_TLS_KEY', '/certs/server.key'),
    'tls_ca': os.environ.get('RP_TLS_CA', '/certs/ca.crt'),
    'state_file': os.environ.get('RP_STATE_FILE', '/data/checks.json'),
    'plugin_dirs': os.environ.get(
        'RP_PLUGIN_DIRS', '/usr/lib/monitoring-plugins'
    ).split(':'),
    'default_interval': int(os.environ.get('RP_DEFAULT_INTERVAL', '60')),
    'check_timeout': int(os.environ.get('RP_CHECK_TIMEOUT', '15')),
}

STARTED_AT = time.time()

# check id -> check definition {id, type, target, args, interval}
CHECKS = {}
# check id -> latest result {id, status, output, perf, checked_at, duration_ms}
RESULTS = {}
# check id -> unix time of next scheduled run
NEXT_RUN = {}
STATE_LOCK = threading.Lock()

PLUGIN_NAME_RE = re.compile(r'^[a-z0-9_.-]+$')


def log(message):
    print('[%s] %s' % (time.strftime('%Y-%m-%d %H:%M:%S'), message), flush=True)


def resolve_plugin(check_type):
    """Map a check type (e.g. 'http') to an executable plugin path."""
    name = 'check_' + check_type.lower()
    if not PLUGIN_NAME_RE.match(name):
        return None
    for plugin_dir in CONFIG['plugin_dirs']:
        path = os.path.realpath(os.path.join(plugin_dir, name))
        if not path.startswith(os.path.realpath(plugin_dir) + os.sep):
            continue
        if os.path.isfile(path) and os.access(path, os.X_OK):
            return path
    return None


def validate_check(raw):
    """Validate and normalize a check definition received from the server."""
    if not isinstance(raw, dict):
        raise ValueError('check must be an object')
    check_id = raw.get('id')
    if not isinstance(check_id, int):
        raise ValueError('check id must be an integer')
    check_type = raw.get('type')
    if not isinstance(check_type, str) or not PLUGIN_NAME_RE.match(check_type.lower()):
        raise ValueError('check %s: invalid type' % check_id)
    if resolve_plugin(check_type) is None:
        raise ValueError('check %s: plugin check_%s not available' % (check_id, check_type))
    target = raw.get('target') or ''
    if not isinstance(target, str):
        raise ValueError('check %s: target must be a string' % check_id)
    args = raw.get('args') or []
    if isinstance(args, str):
        args = shlex.split(args)
    if not isinstance(args, list) or not all(isinstance(a, str) for a in args):
        raise ValueError('check %s: args must be a list of strings' % check_id)
    interval = raw.get('interval') or CONFIG['default_interval']
    if not isinstance(interval, int) or interval < 10:
        interval = CONFIG['default_interval']
    return {
        'id': check_id,
        'type': check_type.lower(),
        'target': target,
        'args': args,
        'interval': interval,
    }


def run_check(check):
    """Execute a single check and return its result dict."""
    plugin = resolve_plugin(check['type'])
    if plugin is None:
        return {
            'id': check['id'],
            'status': 3,
            'output': 'UNKNOWN - plugin check_%s not available on poller' % check['type'],
            'perf': '',
            'checked_at': int(time.time()),
            'duration_ms': 0,
        }

    argv = [plugin]
    if check['target']:
        argv += ['-H', check['target']]
    argv += check['args']

    start = time.time()
    try:
        proc = subprocess.run(
            argv,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=CONFIG['check_timeout'],
        )
        status = proc.returncode
        output = proc.stdout.decode('utf-8', 'replace').strip()
    except subprocess.TimeoutExpired:
        status = 3
        output = 'UNKNOWN - check timed out after %ss' % CONFIG['check_timeout']
    except OSError as exc:
        status = 3
        output = 'UNKNOWN - failed to execute plugin: %s' % exc
    duration_ms = int((time.time() - start) * 1000)

    if status not in (0, 1, 2, 3):
        status = 3

    # Nagios plugin output: "TEXT | perfdata" (first line only)
    first_line = output.split('\n', 1)[0]
    perf = ''
    if '|' in first_line:
        text, perf = first_line.split('|', 1)
        first_line = text.strip()
        perf = perf.strip()

    return {
        'id': check['id'],
        'status': status,
        'output': first_line,
        'perf': perf,
        'checked_at': int(time.time()),
        'duration_ms': duration_ms,
    }


def scheduler_loop():
    """Continuously run due checks on their configured interval."""
    while True:
        now = time.time()
        due = []
        with STATE_LOCK:
            for check_id, check in CHECKS.items():
                if NEXT_RUN.get(check_id, 0) <= now:
                    NEXT_RUN[check_id] = now + check['interval']
                    due.append(dict(check))
        for check in due:
            result = run_check(check)
            with STATE_LOCK:
                # config may have changed while the check was running
                if check['id'] in CHECKS:
                    RESULTS[check['id']] = result
            log('check %s (%s %s) -> status %s in %sms' % (
                check['id'], check['type'], check['target'],
                result['status'], result['duration_ms']))
        time.sleep(1)


def load_state():
    try:
        with open(CONFIG['state_file']) as fh:
            saved = json.load(fh)
    except (OSError, ValueError):
        return
    for raw in saved.get('checks', []):
        try:
            check = validate_check(raw)
        except ValueError as exc:
            log('discarding saved check: %s' % exc)
            continue
        CHECKS[check['id']] = check
    log('loaded %d checks from state file' % len(CHECKS))


def save_state():
    state_dir = os.path.dirname(CONFIG['state_file'])
    if state_dir and not os.path.isdir(state_dir):
        os.makedirs(state_dir, exist_ok=True)
    tmp = CONFIG['state_file'] + '.tmp'
    with open(tmp, 'w') as fh:
        json.dump({'checks': list(CHECKS.values())}, fh)
    os.replace(tmp, CONFIG['state_file'])


def poller_info():
    return {
        'name': CONFIG['poller_name'],
        'version': VERSION,
        'uptime': int(time.time() - STARTED_AT),
        'check_count': len(CHECKS),
        'time': int(time.time()),
    }


def results_payload():
    with STATE_LOCK:
        return {
            'poller': poller_info(),
            'results': list(RESULTS.values()),
        }


class Handler(BaseHTTPRequestHandler):
    server_version = 'librenms-remote-poller/' + VERSION

    def log_message(self, fmt, *args):
        log('%s %s' % (self.address_string(), fmt % args))

    def send_json(self, code, payload):
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == '/api/v1/status':
            with STATE_LOCK:
                self.send_json(200, poller_info())
        elif self.path == '/api/v1/results':
            self.send_json(200, results_payload())
        else:
            self.send_json(404, {'error': 'not found'})

    def do_POST(self):
        if self.path != '/api/v1/sync':
            self.send_json(404, {'error': 'not found'})
            return
        try:
            length = int(self.headers.get('Content-Length', '0'))
            body = json.loads(self.rfile.read(length) or b'{}')
            raw_checks = body.get('checks', [])
            if not isinstance(raw_checks, list):
                raise ValueError('checks must be a list')
        except ValueError as exc:
            self.send_json(400, {'error': str(exc)})
            return

        accepted = {}
        errors = []
        for raw in raw_checks:
            try:
                check = validate_check(raw)
                accepted[check['id']] = check
            except ValueError as exc:
                errors.append(str(exc))

        with STATE_LOCK:
            removed = set(CHECKS) - set(accepted)
            for check_id, check in accepted.items():
                old = CHECKS.get(check_id)
                CHECKS[check_id] = check
                if old != check:
                    NEXT_RUN[check_id] = 0  # changed/new: run as soon as possible
            for check_id in removed:
                CHECKS.pop(check_id, None)
                RESULTS.pop(check_id, None)
                NEXT_RUN.pop(check_id, None)
            try:
                save_state()
            except OSError as exc:
                log('failed to persist state: %s' % exc)

        log('sync: %d checks configured, %d removed, %d rejected' % (
            len(accepted), len(removed), len(errors)))

        payload = results_payload()
        payload['errors'] = errors
        self.send_json(200, payload)


def build_ssl_context():
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(CONFIG['tls_cert'], CONFIG['tls_key'])
    context.load_verify_locations(CONFIG['tls_ca'])
    # mutual TLS: refuse any client without a certificate signed by our CA
    context.verify_mode = ssl.CERT_REQUIRED
    return context


def main():
    for key in ('tls_cert', 'tls_key', 'tls_ca'):
        if not os.path.isfile(CONFIG[key]):
            log('FATAL: %s not found at %s' % (key, CONFIG[key]))
            sys.exit(1)

    load_state()

    worker = threading.Thread(target=scheduler_loop, daemon=True)
    worker.start()

    server = ThreadingHTTPServer(
        (CONFIG['listen_addr'], CONFIG['listen_port']), Handler
    )
    server.socket = build_ssl_context().wrap_socket(server.socket, server_side=True)
    log('remote poller %r listening on https://%s:%s (mTLS required)' % (
        CONFIG['poller_name'], CONFIG['listen_addr'], CONFIG['listen_port']))
    server.serve_forever()


if __name__ == '__main__':
    main()
