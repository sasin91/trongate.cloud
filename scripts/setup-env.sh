#!/bin/bash
# Create .env from .env.example with a generated APP_KEY and database password.
# In the cluster the same values go into the SOPS-encrypted trongate-cloud-env
# Secret (see deploy/k8s/README.md).
set -euo pipefail

ENV_FILE=".env"

if [ -f "$ENV_FILE" ]; then
    echo "Warning: $ENV_FILE already exists."
    read -p "Overwrite? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted. Keeping existing $ENV_FILE"
        exit 0
    fi
fi

set_env() {
    local key="$1" value="$2"
    VALUE="$value" perl -0pi -e "s|^${key}=.*\$|${key}=\"\$ENV{VALUE}\"|m" "$ENV_FILE"
}

cp .env.example "$ENV_FILE"

# Same format as: php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
set_env APP_KEY "$(openssl rand -base64 32)"
set_env MCP_SECRET "$(openssl rand -hex 32)"
set_env DB_PASSWORD "$(openssl rand -hex 24)"
set_env GITHUB_APP_WEBHOOK_SECRET "$(openssl rand -hex 32)"

echo "Created $ENV_FILE with a generated APP_KEY, MCP_SECRET, DB_PASSWORD and GITHUB_APP_WEBHOOK_SECRET"
echo "Register the GitHub App with that webhook secret (docs/github-app.md) and fill in the other GITHUB_APP_* values."
echo "Review the PLATFORM_* values, then copy APP_KEY, MCP_SECRET and DB_PASSWORD into"
echo "deploy/k8s/platform/secrets.example.yaml (as a SOPS-encrypted copy) for the cluster."
