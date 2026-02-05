#!/bin/bash

# Sign SSH User Certificate
# Creates a short-lived certificate for accessing Trongate Cloud servers

set -e

CA_DIR="$HOME/.ssh/trongate-ca"
CA_KEY="$CA_DIR/ca_user_key"

# Parse arguments
USER_PUBLIC_KEY="${1}"
VALIDITY="${2:-+1h}"  # Default: 1 hour
PRINCIPALS="${3:-ubuntu,root}"  # Default: ubuntu and root users

if [ -z "$USER_PUBLIC_KEY" ]; then
    echo "Usage: $0 <public_key_file> [validity] [principals]"
    echo ""
    echo "Examples:"
    echo "  $0 ~/.ssh/id_ed25519.pub                    # 1 hour validity, ubuntu+root"
    echo "  $0 ~/.ssh/id_ed25519.pub +8h                # 8 hour validity"
    echo "  $0 ~/.ssh/id_ed25519.pub +1d ubuntu         # 1 day, ubuntu only"
    echo ""
    echo "Validity format: +Xh (hours), +Xd (days), +Xw (weeks)"
    echo "Common principals: ubuntu, root, deploy"
    exit 1
fi

# Check if CA exists
if [ ! -f "$CA_KEY" ]; then
    echo "❌ Error: Certificate Authority not found at: $CA_KEY"
    echo "Run ./scripts/setup-ssh-ca.sh first to create the CA."
    exit 1
fi

# Check if user public key exists
if [ ! -f "$USER_PUBLIC_KEY" ]; then
    echo "❌ Error: Public key not found: $USER_PUBLIC_KEY"
    exit 1
fi

# Determine certificate output path (same as key, with -cert.pub extension)
CERT_PATH="${USER_PUBLIC_KEY%.pub}-cert.pub"

# Get key identifier (username@hostname or comment)
KEY_ID=$(ssh-keygen -l -f "$USER_PUBLIC_KEY" | awk '{print $NF}')
if [ -z "$KEY_ID" ] || [ "$KEY_ID" = "(stdin)" ]; then
    KEY_ID="trongate-platform-$(date +%s)"
fi

echo "==================================================="
echo "Signing SSH Certificate"
echo "==================================================="
echo "Public Key:  $USER_PUBLIC_KEY"
echo "Certificate: $CERT_PATH"
echo "Identity:    $KEY_ID"
echo "Validity:    $VALIDITY"
echo "Principals:  $PRINCIPALS"
echo "==================================================="
echo ""

# Sign the certificate
ssh-keygen \
    -s "$CA_KEY" \
    -I "$KEY_ID" \
    -n "$PRINCIPALS" \
    -V "$VALIDITY" \
    "$USER_PUBLIC_KEY"

echo ""
echo "✅ Certificate signed successfully!"
echo ""
echo "Certificate details:"
ssh-keygen -L -f "$CERT_PATH"
echo ""
echo "==================================================="
echo "Usage:"
echo "==================================================="
echo "The certificate is automatically used if placed next to your private key."
echo ""
echo "Test connection:"
echo "  ssh -i ${USER_PUBLIC_KEY%.pub} ubuntu@<server-ip>"
echo ""
echo "Certificate location: $CERT_PATH"
echo "Valid for: $VALIDITY from now"
echo ""
echo "Note: The certificate will be automatically used by ssh if it's in the"
echo "same directory as your private key with the -cert.pub suffix."
echo "==================================================="
