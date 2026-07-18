# Remote Service Polling

Remote service polling lets you run [service checks](Services.md) (icmp,
http, database, ...) from lightweight poller agents deployed close to the
monitored targets — for example inside a customer network, a DMZ, or a
remote site — while the results are collected and displayed centrally.

Unlike the full [distributed poller](../Extensions/Distributed-Poller.md)
setup, a remote service poller:

- is a single small container (Alpine + monitoring-plugins, no PHP, no
  database, no Redis),
- only performs service checks, not SNMP polling or discovery,
- is *pulled* by the main server — the agent never connects to LibreNMS, so
  it needs no credentials for your central installation,
- authenticates with mutual TLS certificates in both directions.

## Architecture

1. Services are assigned to one or more remote pollers in the service
   add/edit dialog ("Remote Pollers" selection). The service keeps being
   checked locally by the normal services poller as well.
2. Every minute the Laravel scheduler runs `lnms remote-poller:fetch`. For
   each enabled remote poller it POSTs the assigned check definitions to
   `https://<poller>/api/v1/sync` and receives the latest cached results.
3. The agent runs its configured checks continuously on its own schedule
   (`remote_pollers.check_frequency`, default 60s) so results are always
   fresh when fetched.
4. Results (status, output, perfdata, timestamp) are stored per
   service/poller pair and shown on the Remote Pollers page. Status changes
   are logged to the event log.

## Setting up

### 1. Generate certificates

```bash
./scripts/gen-remote-poller-certs.sh /path/to/certs poller1.example.com
```

This creates a CA plus a server certificate (for the agent, SANs taken from
the arguments) and a client certificate (for the main server). Re-run the
script with the same output directory to issue certificates for additional
pollers from the same CA.

### 2. Deploy the agent

Build and run the container in `remote-poller/`:

```bash
cd remote-poller
docker build -t librenms-remote-poller .
docker run -d --cap-add NET_RAW -p 8443:8443 \
    -v /path/to/certs:/certs:ro -v poller-data:/data \
    -e RP_POLLER_NAME=poller1 librenms-remote-poller
```

The agent needs `ca.crt`, `server.crt` and `server.key` in `/certs`.

### 3. Configure the main server

Copy `ca.crt`, `client.crt` and `client.key` to the main server and point
LibreNMS at them (defaults shown):

```bash
lnms config:set remote_pollers.tls_ca /certs/ca.crt
lnms config:set remote_pollers.tls_cert /certs/client.crt
lnms config:set remote_pollers.tls_key /certs/client.key
```

Optional settings:

```bash
lnms config:set remote_pollers.check_frequency 60  # seconds between checks on the agent
lnms config:set remote_pollers.timeout 15          # HTTP timeout contacting agents
```

### 4. Register the poller

Via the web UI (gear menu → Poller → Remote Pollers) or the CLI:

```bash
lnms remote-poller:add poller1 https://poller1.example.com:8443
lnms remote-poller:list
```

The add command verifies connectivity (use `--skip-check` to skip).

### 5. Assign services

Edit or create a service on a device and select one or more entries in the
new **Remote Pollers** field. On the next scheduler run the check definition
is pushed to the selected agents and results start flowing back.

## CLI reference

| Command                              | Description                                    |
| ------------------------------------ | ---------------------------------------------- |
| `lnms remote-poller:add <name> <url>` | Register a poller (`--disabled`, `--skip-check`) |
| `lnms remote-poller:list`             | List pollers with status and last contact     |
| `lnms remote-poller:remove <poller>`  | Remove a poller and its assignments           |
| `lnms remote-poller:fetch [poller]`   | Sync + fetch results now (all or one poller)  |

## Security notes

- The agent refuses any HTTPS connection without a client certificate signed
  by the CA — keep `ca.key` safe; anyone holding a certificate signed by the
  CA can read results and assign checks (which execute monitoring plugins on
  the agent).
- Check commands are executed without a shell and plugin names are
  restricted to `check_[a-z0-9_.-]+` inside the configured plugin directory.
- Use one CA per LibreNMS installation and issue one server certificate per
  poller.

## Limitations

- Remote results are stored per service/poller pair and shown in the UI and
  event log; they do not feed RRD graphs or alert rules yet.
- The service's regular local check keeps running; remote checks are an
  addition, not a replacement.
