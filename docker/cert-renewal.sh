#!/bin/bash
set -e

CA_KEY="/ca/ca_user_key"
PLATFORM_KEY="/certs/platform_key"
PLATFORM_CERT="/certs/platform_key-cert.pub"
VALIDITY_HOURS="${CERT_VALIDITY_HOURS:-24}"
RENEWAL_THRESHOLD_HOURS="${RENEWAL_THRESHOLD_HOURS:-6}"
CHECK_INTERVAL_SECONDS="${CHECK_INTERVAL_SECONDS:-300}"

echo "[cert-manager] Starting certificate manager"
echo "[cert-manager] Certificate validity: ${VALIDITY_HOURS}h"
echo "[cert-manager] Renewal threshold: ${RENEWAL_THRESHOLD_HOURS}h before expiry"
echo "[cert-manager] Check interval: ${CHECK_INTERVAL_SECONDS}s"

# Write CA key from environment variable
if [ -z "$CA_PRIVATE_KEY" ]; then
    echo "[cert-manager] ERROR: CA_PRIVATE_KEY environment variable not set"
    exit 1
fi

echo "[cert-manager] Writing CA key from environment variable"
mkdir -p /ca
echo "$CA_PRIVATE_KEY" > "$CA_KEY"
chmod 600 "$CA_KEY"

# Validate CA key is valid
if ! ssh-keygen -l -f "$CA_KEY" >/dev/null 2>&1; then
    echo "[cert-manager] ERROR: CA key is not a valid SSH key"
    exit 1
fi

echo "[cert-manager] CA key loaded successfully"

# Function to parse ISO8601 timestamp to epoch (works with busybox date)
parse_timestamp() {
    local ts="$1"
    # Convert 2026-02-06T12:00:00 to 202602061200
    local formatted=$(echo "$ts" | sed 's/[-:]//g; s/T//; s/^\([0-9]\{12\}\).*/\1/')
    # Use busybox date: -D format -d input
    date -D "%Y%m%d%H%M" +%s -d "$formatted" 2>/dev/null || echo "0"
}

# Function to check if certificate needs renewal
needs_renewal() {
    # If cert doesn't exist, needs renewal
    if [ ! -f "$PLATFORM_CERT" ]; then
        echo "[cert-manager] Certificate not found, needs creation"
        return 0
    fi

    # Get certificate info
    local cert_info=$(ssh-keygen -L -f "$PLATFORM_CERT" 2>/dev/null || echo "")

    if [ -z "$cert_info" ]; then
        echo "[cert-manager] Cannot read certificate, needs renewal"
        return 0
    fi

    # Extract the "Valid: from X to Y" line
    local valid_line=$(echo "$cert_info" | grep "Valid:" || echo "")

    if [ -z "$valid_line" ]; then
        echo "[cert-manager] Cannot parse certificate validity, needs renewal"
        return 0
    fi

    # Extract expiry timestamp (format: "Valid: from 2026-02-06T12:00:00 to 2026-02-07T12:00:00")
    local expiry=$(echo "$valid_line" | sed -n 's/.*to \([0-9T:-]\+\).*/\1/p')

    if [ -z "$expiry" ]; then
        echo "[cert-manager] Cannot extract expiry time, needs renewal"
        return 0
    fi

    # Parse timestamps
    local expiry_ts=$(parse_timestamp "$expiry")
    local now_ts=$(date +%s)
    local threshold_seconds=$((RENEWAL_THRESHOLD_HOURS * 3600))

    if [ "$expiry_ts" -eq 0 ]; then
        echo "[cert-manager] Cannot parse expiry timestamp, needs renewal"
        return 0
    fi

    local time_until_expiry=$((expiry_ts - now_ts))

    # If certificate expires within threshold, renew it
    if [ "$time_until_expiry" -lt "$threshold_seconds" ]; then
        local hours_remaining=$((time_until_expiry / 3600))
        echo "[cert-manager] Certificate expires in ${hours_remaining}h, needs renewal"
        return 0
    fi

    local hours_remaining=$((time_until_expiry / 3600))
    echo "[cert-manager] Certificate valid for ${hours_remaining}h, no renewal needed"
    return 1
}

# Function to generate platform key if it doesn't exist
ensure_platform_key() {
    if [ ! -f "$PLATFORM_KEY" ]; then
        echo "[cert-manager] Generating platform SSH key"
        ssh-keygen -t ed25519 -f "$PLATFORM_KEY" -N "" -C "platform@trongate.cloud"
    fi
}

# Function to sign certificate
sign_certificate() {
    echo "[cert-manager] Signing new certificate (validity: ${VALIDITY_HOURS}h)"

    local key_id="platform-$(date +%Y%m%d%H%M%S)"
    local validity="+${VALIDITY_HOURS}h"
    local principals="ubuntu,root"

    ssh-keygen -s "$CA_KEY" \
        -I "$key_id" \
        -n "$principals" \
        -V "$validity" \
        "${PLATFORM_KEY}.pub"

    echo "[cert-manager] Certificate signed successfully: $key_id"

    # Display certificate info
    echo "[cert-manager] Certificate details:"
    ssh-keygen -L -f "$PLATFORM_CERT" | grep -E "(Key ID|Valid|Principals)" || true

    # Update timestamp file to trigger worker reload
    date +%s > /certs/cert_updated_at
}

# Main loop
while true; do
    echo "[cert-manager] Checking certificate status at $(date)"

    # Ensure platform key exists
    ensure_platform_key

    # Check if renewal is needed and renew if necessary
    if needs_renewal; then
        sign_certificate
    fi

    # Sleep until next check
    echo "[cert-manager] Next check in ${CHECK_INTERVAL_SECONDS}s"
    sleep "$CHECK_INTERVAL_SECONDS"
done
