#!/bin/bash

# Setup SSH Certificate Authority
# This script creates the CA key pair for signing user certificates

set -e

CA_DIR="$HOME/.ssh/trongate-ca"
CA_KEY="$CA_DIR/ca_user_key"
COMMENT="ca@trongate.cloud"

echo "==================================================="
echo "Trongate Cloud - SSH Certificate Authority Setup"
echo "==================================================="
echo ""

# Create CA directory
mkdir -p "$CA_DIR"
chmod 700 "$CA_DIR"

# Check if CA already exists
if [ -f "$CA_KEY" ]; then
    echo "⚠️  Certificate Authority already exists at: $CA_KEY"
    echo ""
    read -p "Do you want to use this existing CA? (y/n): " use_existing

    if [ "$use_existing" != "y" ]; then
        echo "Aborting. Please manually backup or remove the existing CA first."
        exit 1
    fi
else
    echo "📝 Generating new ED25519 Certificate Authority key..."
    ssh-keygen -t ed25519 -C "$COMMENT" -f "$CA_KEY" -N ""
    echo "✅ CA key pair generated successfully!"
    echo ""
fi

# Set proper permissions
chmod 600 "$CA_KEY"
chmod 644 "$CA_KEY.pub"

# Read the public key
CA_PUBLIC_KEY=$(cat "$CA_KEY.pub")

echo ""
echo "==================================================="
echo "Certificate Authority Public Key:"
echo "==================================================="
echo "$CA_PUBLIC_KEY"
echo ""
echo "==================================================="
echo ""

# Offer to copy to clipboard (if available)
if command -v xclip &> /dev/null; then
    echo "$CA_PUBLIC_KEY" | xclip -selection clipboard
    echo "✅ CA public key copied to clipboard!"
elif command -v pbcopy &> /dev/null; then
    echo "$CA_PUBLIC_KEY" | pbcopy
    echo "✅ CA public key copied to clipboard!"
fi

echo ""
echo "Next steps:"
echo ""
echo "1. Set the environment variable:"
echo "   export PLATFORM_SSH_CA_PUBLIC_KEY='$CA_PUBLIC_KEY'"
echo ""
echo "2. Or edit config/platform.php and set:"
echo "   define('PLATFORM_SSH_CA_PUBLIC_KEY', '$CA_PUBLIC_KEY');"
echo ""
echo "3. To sign user certificates, use:"
echo "   ./scripts/sign-ssh-cert.sh ~/.ssh/id_ed25519.pub"
echo ""
echo "Security Notes:"
echo "  🔒 CA private key: $CA_KEY"
echo "     Keep this EXTREMELY secure - it can sign certificates for all servers!"
echo "     Consider using a hardware security module (HSM) in production."
echo ""
echo "  📄 CA public key: $CA_KEY.pub"
echo "     This is safe to distribute and will be added to all servers."
echo ""
echo "==================================================="
