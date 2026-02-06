#!/bin/bash
set -e

ENV_FILE=".env"
CA_PRIVATE_KEY_PATH="$HOME/.ssh/trongate-ca/ca_user_key"
CA_PUBLIC_KEY_PATH="$HOME/.ssh/trongate-ca/ca_user_key.pub"

echo "Setting up .env file for Docker Compose..."

# Check if .env already exists
if [ -f "$ENV_FILE" ]; then
    echo "Warning: $ENV_FILE already exists."
    read -p "Overwrite? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted. Keeping existing $ENV_FILE"
        exit 0
    fi
fi

# Check if CA keys exist
if [ ! -f "$CA_PRIVATE_KEY_PATH" ]; then
    echo "ERROR: CA private key not found at $CA_PRIVATE_KEY_PATH"
    echo "Run ./scripts/setup-ssh-ca.sh first to generate the CA keys"
    exit 1
fi

if [ ! -f "$CA_PUBLIC_KEY_PATH" ]; then
    echo "ERROR: CA public key not found at $CA_PUBLIC_KEY_PATH"
    echo "Run ./scripts/setup-ssh-ca.sh first to generate the CA keys"
    exit 1
fi

# Read CA keys
echo "Reading CA keys..."
CA_PRIVATE_KEY_CONTENT=$(cat "$CA_PRIVATE_KEY_PATH")
CA_PUBLIC_KEY_CONTENT=$(cat "$CA_PUBLIC_KEY_PATH")

# Create .env file
cat > "$ENV_FILE" << EOF
# HTTP port for Traefik (default: 8085)
HTTP_PORT=8085

# CA private key (REQUIRED)
# This key is used by the cert-manager to sign platform certificates
CA_PRIVATE_KEY="$CA_PRIVATE_KEY_CONTENT"

# CA public key (REQUIRED)
# This key is distributed to all provisioned servers to trust platform certificates
PLATFORM_SSH_CA_PUBLIC_KEY="$CA_PUBLIC_KEY_CONTENT"

# Certificate validity in hours (default: 24)
CERT_VALIDITY_HOURS=24

# Renew certificate when this many hours remain before expiry (default: 6)
RENEWAL_THRESHOLD_HOURS=6

# How often to check certificate expiry in seconds (default: 300 = 5 minutes)
CHECK_INTERVAL_SECONDS=300
EOF

echo "✓ Created $ENV_FILE"
echo "✓ CA private key loaded from $CA_PRIVATE_KEY_PATH"
echo "✓ CA public key loaded from $CA_PUBLIC_KEY_PATH"
echo
echo "Configuration:"
echo "  - HTTP_PORT: 8085"
echo "  - CERT_VALIDITY_HOURS: 24"
echo "  - RENEWAL_THRESHOLD_HOURS: 6"
echo "  - CHECK_INTERVAL_SECONDS: 300"
echo
echo "You can now run: docker-compose up -d"
