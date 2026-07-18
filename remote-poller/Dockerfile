FROM alpine:3.20

# monitoring-plugins provides the Nagios-compatible check_* binaries
# (check_icmp, check_ping, check_http, check_mysql, check_pgsql, check_tcp, ...)
RUN apk add --no-cache \
        python3 \
        monitoring-plugins \
        fping \
        libcap \
    && setcap cap_net_raw+ep /usr/lib/monitoring-plugins/check_icmp || true

COPY remote_poller.py /usr/local/bin/remote_poller.py

ENV RP_PORT=8443 \
    RP_TLS_CERT=/certs/server.crt \
    RP_TLS_KEY=/certs/server.key \
    RP_TLS_CA=/certs/ca.crt \
    RP_STATE_FILE=/data/checks.json \
    RP_PLUGIN_DIRS=/usr/lib/monitoring-plugins

VOLUME /data
EXPOSE 8443

HEALTHCHECK --interval=30s --timeout=5s \
    CMD nc -z 127.0.0.1 ${RP_PORT} || exit 1

CMD ["python3", "/usr/local/bin/remote_poller.py"]
