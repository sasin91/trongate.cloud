<?php

/**
 * Deploy Repository Task
 *
 * Downloads a repository tarball via API and deploys to the server.
 * Uses authenticated API calls to support private repositories.
 *
 * Available variables:
 *   $payload   - array from server_tasks.payload JSON
 *   $server_id - int from server_tasks.server_id
 *
 * Return: a shell script string to be executed via `sh -c`.
 */

$source_provider = $payload['source_provider'] ?? 'github';
$source_url = $payload['source_url'] ?? '';
$source_token = $payload['source_token'] ?? '';
$branch = $payload['source_branch'] ?? 'main';
$project_id = (int) ($payload['project_id'] ?? 0);
$project_type = $payload['project_type'] ?? 'trongate_v2';
$server_ip = $payload['server_ip'] ?? '';
$db_password = $payload['db_password'] ?? '';
$db_root_password = $payload['db_root_password'] ?? '';

if (empty($source_url) || empty($server_ip)) {
    return 'echo "ERROR: Missing repository or server_ip in payload" && exit 1';
}

if (empty($source_token)) {
    return 'echo "ERROR: Missing source_token in payload" && exit 1';
}

if (empty($db_password) || empty($db_root_password)) {
    return 'echo "ERROR: Missing database credentials in payload" && exit 1';
}

// Extract owner/repo from source_url (e.g., https://github.com/owner/repo -> owner/repo)
$repo = preg_replace('#^https?://[^/]+/#', '', rtrim($source_url, '/'));
if (empty($repo)) {
    return 'echo "ERROR: Could not parse repository from source_url" && exit 1';
}

// Build API URL for tarball download based on provider
$tarball_api_url = match($source_provider) {
    'github' => "https://api.github.com/repos/{$repo}/tarball/{$branch}",
    default => '' // Future: GitLab, Gitea, etc.
};

if (empty($tarball_api_url)) {
    return 'echo "ERROR: Unsupported source provider: ' . $source_provider . '" && exit 1';
}

$escaped_tarball_url = escapeshellarg($tarball_api_url);
$escaped_token = escapeshellarg($source_token);
$escaped_branch = escapeshellarg($branch);
$escaped_db_password = escapeshellarg($db_password);
$escaped_db_root_password = escapeshellarg($db_root_password);

$app_dir = "/var/www/project_{$project_id}";

// Determine docker image based on project type
$docker_image = match($project_type) {
    'trongate_v1' => 'ghcr.io/trongate/trongate-v1:latest',
    'trongate_v2' => 'ghcr.io/trongate/trongate-v2:latest',
    default => 'ghcr.io/trongate/trongate-v2:latest'
};

// SSH options for non-interactive, trust new hosts
// Uses platform CA-signed certificate mounted at /root/.ssh/id_ed25519
$ssh_opts = '-i /root/.ssh/id_ed25519 -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=30';

return <<<BASH
#!/bin/bash
set -e

echo "=== Starting deployment ==="
echo "Provider: {$source_provider}"
echo "Repository: {$repo}"
echo "Branch: {$branch}"
echo "Project ID: {$project_id}"
echo "Server IP: {$server_ip}"

# Verify SSH key exists
echo "Checking SSH key..."
if [ -f /root/.ssh/id_ed25519 ]; then
    echo "SSH private key found: /root/.ssh/id_ed25519"
    ls -la /root/.ssh/
else
    echo "ERROR: SSH private key not found at /root/.ssh/id_ed25519"
    exit 1
fi

if [ -f /root/.ssh/id_ed25519-cert.pub ]; then
    echo "SSH certificate found: /root/.ssh/id_ed25519-cert.pub"
else
    echo "WARNING: SSH certificate not found at /root/.ssh/id_ed25519-cert.pub"
fi

# Wait for server to be ready (cloud-init to complete)
echo "Waiting for server to be ready..."
MAX_RETRIES=60
RETRY_COUNT=0

while [ \$RETRY_COUNT -lt \$MAX_RETRIES ]; do
    echo "Attempting SSH connection to root@{$server_ip}..."
    SSH_OUTPUT=\$(ssh {$ssh_opts} root@{$server_ip} "test -f /root/cloud-init-complete && echo 'READY'" 2>&1)
    SSH_EXIT=\$?
    echo "SSH exit code: \$SSH_EXIT"
    echo "SSH output: \$SSH_OUTPUT"

    if [ "\$SSH_OUTPUT" = "READY" ]; then
        echo "Server ready!"
        break
    fi

    RETRY_COUNT=\$((RETRY_COUNT + 1))
    if [ \$RETRY_COUNT -eq \$MAX_RETRIES ]; then
        echo "ERROR: Timeout waiting for server to be ready"
        exit 1
    fi

    echo "Waiting for cloud-init to complete... (\$RETRY_COUNT/\$MAX_RETRIES)"
    sleep 5
done

echo "Deploying to server..."

# Execute deployment on remote server
ssh {$ssh_opts} root@{$server_ip} bash -s << 'REMOTE_SCRIPT'
set -e

APP_DIR="{$app_dir}"
TARBALL_URL={$escaped_tarball_url}
SOURCE_TOKEN={$escaped_token}
BRANCH={$escaped_branch}
PROJECT_ID="project_{$project_id}"
DOCKER_IMAGE="{$docker_image}"
DB_PASSWORD={$escaped_db_password}
DB_ROOT_PASSWORD={$escaped_db_root_password}

echo "Creating application directory..."
mkdir -p \$APP_DIR
cd \$APP_DIR

# Download repository tarball via authenticated API
echo "Downloading repository tarball..."
curl -fsSL -H "Authorization: Bearer \$SOURCE_TOKEN" -H "Accept: application/vnd.github+json" "\$TARBALL_URL" -o /tmp/repo.tar.gz

# Extract tarball (--strip-components=1 removes the top-level directory GitHub adds)
echo "Extracting repository..."
tar -xzf /tmp/repo.tar.gz --strip-components=1
rm /tmp/repo.tar.gz

SERVER_IP=\$(hostname -I | awk '{print \$1}')

# Create .env file
echo "Setting up environment..."
cat > .env << EOF
PROJECT_ID=\${PROJECT_ID}
DOMAIN=\${SERVER_IP}.nip.io
DB_NAME=app
DB_USER=app
DB_PASSWORD=\${DB_PASSWORD}
DB_ROOT_PASSWORD=\${DB_ROOT_PASSWORD}
ACME_EMAIL=admin@example.com
ENV=production
BASE_URL=http://\${SERVER_IP}/
EOF

# Create docker-compose.yml if it doesn't exist
if [ ! -f "docker-compose.yml" ]; then
    echo "Creating docker-compose.yml..."
    cat > docker-compose.yml << EOF
services:
  app:
    image: \${DOCKER_IMAGE}
    container_name: \${PROJECT_ID}_app
    environment:
      - DB_HOST=db
      - DB_USER=app
      - DB_PASSWORD=\${DB_PASSWORD}
      - DB_NAME=app
      - ENV=production
      - BASE_URL=http://\${SERVER_IP}/
    depends_on:
      - db
    ports:
      - "80:80"
    volumes:
      - ./:/var/www/html

  db:
    image: mariadb:11.2
    container_name: \${PROJECT_ID}_db
    environment:
      - MYSQL_ROOT_PASSWORD=\${DB_ROOT_PASSWORD}
      - MYSQL_DATABASE=app
      - MYSQL_USER=app
      - MYSQL_PASSWORD=\${DB_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql

volumes:
  db_data:
EOF
fi

# Start containers
echo "Starting Docker containers..."
docker compose pull
docker compose up -d

echo "=== Deployment complete ==="
echo "Application deployed to: http://\${SERVER_IP}/"
REMOTE_SCRIPT

echo "=== Deployment finished successfully ==="
BASH;
