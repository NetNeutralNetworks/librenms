# LibreNMS Remote Service Poller

A lightweight agent that performs Nagios-compatible service checks (icmp,
http, mysql, pgsql, tcp, ...) close to the monitored targets and serves the
results to the main LibreNMS server over HTTPS with mutual TLS.

## How it works

1. The main LibreNMS server periodically runs `lnms remote-poller:fetch`
   (scheduled every minute) which calls `POST /api/v1/sync` on every enabled
   remote poller.
2. The sync request contains the full list of checks assigned to this poller
   (services with this remote poller selected in the service form). The
   response contains the latest cached results.
3. Between syncs the agent runs each check on its own schedule (default every
   60s) using the `check_*` plugins from `monitoring-plugins`, and caches the
   results in memory (config is persisted to `RP_STATE_FILE` so checks
   continue across restarts).

Authentication is mutual TLS: the agent only accepts connections presenting a
client certificate signed by the configured CA, and the main server verifies
the agent's server certificate against the same CA. Generate certificates
with `scripts/gen-remote-poller-certs.sh`.

## API

| Method | Path             | Description                                       |
| ------ | ---------------- | ------------------------------------------------- |
| GET    | `/api/v1/status`  | Poller name, version, uptime, check count        |
| GET    | `/api/v1/results` | Latest cached results                            |
| POST   | `/api/v1/sync`    | Replace check config, returns latest results     |

Check definition format (sent by the main server):

```json
{
  "checks": [
    {"id": 42, "type": "http", "target": "www.example.com", "args": "-S", "interval": 60}
  ]
}
```

`type` maps to the `check_<type>` plugin, `target` is passed as `-H`, `args`
is an extra argument string (shell-style quoting, executed without a shell),
`id` is the LibreNMS service_id.

Result format:

```json
{
  "poller": {"name": "poller1", "version": "1.0.0", "uptime": 3600, "check_count": 1, "time": 1752825600},
  "results": [
    {"id": 42, "status": 0, "output": "HTTP OK ...", "perf": "time=0.1s;;;0", "checked_at": 1752825590, "duration_ms": 104}
  ],
  "errors": []
}
```

`status` uses Nagios plugin exit codes: 0=OK, 1=Warning, 2=Critical, 3=Unknown.

## Configuration (environment variables)

| Variable              | Default                        | Description                          |
| --------------------- | ------------------------------ | ------------------------------------ |
| `RP_POLLER_NAME`      | hostname                       | Name reported to the main server     |
| `RP_LISTEN_ADDR`      | `0.0.0.0`                      | Listen address                       |
| `RP_PORT`             | `8443`                         | Listen port (HTTPS)                  |
| `RP_TLS_CERT`         | `/certs/server.crt`            | Server certificate                   |
| `RP_TLS_KEY`          | `/certs/server.key`            | Server private key                   |
| `RP_TLS_CA`           | `/certs/ca.crt`                | CA used to verify client certs       |
| `RP_STATE_FILE`       | `/data/checks.json`            | Persisted check configuration        |
| `RP_PLUGIN_DIRS`      | `/usr/lib/monitoring-plugins`  | Colon-separated plugin search path   |
| `RP_DEFAULT_INTERVAL` | `60`                           | Default check interval (seconds)     |
| `RP_CHECK_TIMEOUT`    | `15`                           | Per-check execution timeout          |

## Running

```bash
docker build -t librenms-remote-poller .
docker run -d --name remote-poller \
    --cap-add NET_RAW \
    -p 8443:8443 \
    -v /path/to/certs:/certs:ro \
    -v remote-poller-data:/data \
    -e RP_POLLER_NAME=poller1 \
    librenms-remote-poller
```

`NET_RAW` is needed for `check_icmp`. Additional check plugins can be added
by installing extra packages into the image (see Alpine's `monitoring-plugins`
and related packages).

See `doc/Extensions/Remote-Service-Polling.md` for the full setup guide
including the main-server side.
