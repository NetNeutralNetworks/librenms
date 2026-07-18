#!/usr/bin/env bash
#
# Generate a CA, server certificate, and client certificate for
# LibreNMS remote service poller mutual-TLS authentication.
#
#   ca.crt / ca.key         - certificate authority (shared trust anchor)
#   server.crt / server.key - presented by the remote poller
#   client.crt / client.key - presented by the main LibreNMS server
#
# Usage: gen-remote-poller-certs.sh <output-dir> [server-hostname-or-ip ...]
#
# The given hostnames/IPs are added to the server certificate's SANs.
# Re-running with an existing CA only regenerates the server/client certs.

set -euo pipefail

OUT=${1:?Usage: $0 <output-dir> [server-hostname-or-ip ...]}
shift || true
HOSTS=("$@")
if [ ${#HOSTS[@]} -eq 0 ]; then
    HOSTS=(remote-poller)
fi

DAYS=${DAYS:-3650}
mkdir -p "$OUT"

# --- CA ---------------------------------------------------------------
if [ ! -f "$OUT/ca.crt" ]; then
    echo "Generating CA..."
    openssl req -x509 -newkey rsa:4096 -sha256 -days "$DAYS" -nodes \
        -keyout "$OUT/ca.key" -out "$OUT/ca.crt" \
        -subj "/CN=LibreNMS Remote Poller CA" \
        -addext "basicConstraints=critical,CA:TRUE" \
        -addext "keyUsage=critical,keyCertSign,cRLSign"
else
    echo "Reusing existing CA at $OUT/ca.crt"
fi

# --- server certificate (remote poller) --------------------------------
SAN="DNS:localhost,IP:127.0.0.1"
for h in "${HOSTS[@]}"; do
    if [[ $h =~ ^[0-9.]+$ || $h == *:*:* ]]; then
        SAN="$SAN,IP:$h"
    else
        SAN="$SAN,DNS:$h"
    fi
done

echo "Generating server certificate (SAN: $SAN)..."
openssl req -newkey rsa:2048 -sha256 -nodes \
    -keyout "$OUT/server.key" -out "$OUT/server.csr" \
    -subj "/CN=${HOSTS[0]}"
openssl x509 -req -sha256 -days "$DAYS" \
    -in "$OUT/server.csr" -CA "$OUT/ca.crt" -CAkey "$OUT/ca.key" -CAcreateserial \
    -extfile <(printf 'subjectAltName=%s\nextendedKeyUsage=serverAuth\nkeyUsage=digitalSignature,keyEncipherment\n' "$SAN") \
    -out "$OUT/server.crt"

# --- client certificate (main LibreNMS server) --------------------------
echo "Generating client certificate..."
openssl req -newkey rsa:2048 -sha256 -nodes \
    -keyout "$OUT/client.key" -out "$OUT/client.csr" \
    -subj "/CN=librenms-server"
openssl x509 -req -sha256 -days "$DAYS" \
    -in "$OUT/client.csr" -CA "$OUT/ca.crt" -CAkey "$OUT/ca.key" -CAcreateserial \
    -extfile <(printf 'extendedKeyUsage=clientAuth\nkeyUsage=digitalSignature,keyEncipherment\n') \
    -out "$OUT/client.crt"

rm -f "$OUT/server.csr" "$OUT/client.csr"
chmod 600 "$OUT"/*.key
chmod 644 "$OUT"/*.crt

echo
echo "Certificates written to $OUT:"
ls -l "$OUT"
echo
echo "Remote poller needs: ca.crt, server.crt, server.key"
echo "Main LibreNMS needs: ca.crt, client.crt, client.key"
