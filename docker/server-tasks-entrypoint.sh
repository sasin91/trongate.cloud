#!/bin/bash
set -e

CERT_SOURCE_DIR="/platform-certs"
SSH_DIR="/root/.ssh"

echo "[tcloud_tasks] Starting entrypoint"

# Wait for certificates to be available (cert-manager may be generating them)
echo "[tcloud_tasks] Waiting for platform certificates..."
MAX_WAIT=60
WAITED=0
while [ ! -f "$CERT_SOURCE_DIR/platform_key" ] || [ ! -f "$CERT_SOURCE_DIR/platform_key-cert.pub" ]; do
    if [ $WAITED -ge $MAX_WAIT ]; then
        echo "[tcloud_tasks] ERROR: Certificates not available after ${MAX_WAIT}s"
        exit 1
    fi
    sleep 1
    WAITED=$((WAITED + 1))
done

echo "[tcloud_tasks] Certificates found, copying to SSH directory"

# Copy private key
cp "$CERT_SOURCE_DIR/platform_key" "$SSH_DIR/id_ed25519"
chmod 600 "$SSH_DIR/id_ed25519"

# Copy certificate
cp "$CERT_SOURCE_DIR/platform_key-cert.pub" "$SSH_DIR/id_ed25519-cert.pub"
chmod 644 "$SSH_DIR/id_ed25519-cert.pub"

# Verify certificate
echo "[tcloud_tasks] Certificate details:"
ssh-keygen -L -f "$SSH_DIR/id_ed25519-cert.pub" | grep -E "(Type|Key ID|Serial|Valid|Principals)" || true

echo "[tcloud_tasks] Starting task worker"

# Execute the main command
exec "$@"
