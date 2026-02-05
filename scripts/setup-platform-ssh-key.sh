#!/bin/bash

# Setup Platform SSH Key
# This script helps generate and configure the platform SSH key for Trongate Cloud

set -e

KEY_NAME="trongate_cloud_platform"
KEY_PATH="$HOME/.ssh/$KEY_NAME"
COMMENT="platform@trongate.cloud"

echo "==================================================="
echo "Trongate Cloud - Platform SSH Key Setup"
echo "==================================================="
echo ""

# Check if key already exists
if [ -f "$KEY_PATH" ]; then
    echo "⚠️  Platform SSH key already exists at: $KEY_PATH"
    echo ""
    read -p "Do you want to use this existing key? (y/n): " use_existing

    if [ "$use_existing" != "y" ]; then
        echo "Aborting. Please manually remove or rename the existing key first."
        exit 1
    fi
else
    echo "📝 Generating new ED25519 SSH key..."
    ssh-keygen -t ed25519 -C "$COMMENT" -f "$KEY_PATH" -N ""
    echo "✅ Key generated successfully!"
    echo ""
fi

# Set proper permissions
chmod 600 "$KEY_PATH"
chmod 644 "$KEY_PATH.pub"

# Read the public key
PUBLIC_KEY=$(cat "$KEY_PATH.pub")

echo ""
echo "==================================================="
echo "Platform SSH Public Key:"
echo "==================================================="
echo "$PUBLIC_KEY"
echo ""
echo "==================================================="
echo ""

# Offer to copy to clipboard (if available)
if command -v xclip &> /dev/null; then
    echo "$PUBLIC_KEY" | xclip -selection clipboard
    echo "✅ Public key copied to clipboard!"
elif command -v pbcopy &> /dev/null; then
    echo "$PUBLIC_KEY" | pbcopy
    echo "✅ Public key copied to clipboard!"
fi

echo ""
echo "Next steps:"
echo ""
echo "1. Set the environment variable:"
echo "   export PLATFORM_SSH_PUBLIC_KEY='$PUBLIC_KEY'"
echo ""
echo "2. Or edit config/platform.php and set:"
echo "   define('PLATFORM_SSH_PUBLIC_KEY', '$PUBLIC_KEY');"
echo ""
echo "3. For SSH agent (optional):"
echo "   ssh-add $KEY_PATH"
echo ""
echo "4. Private key location: $KEY_PATH"
echo "   🔒 Keep this secure and never commit to version control!"
echo ""
echo "==================================================="
