<?php

/**
 * Deploy Repository Task
 *
 * Clones a git repository to the server and sets up the application.
 * Supports multiple source providers: GitHub, GitLab, Gitea, etc.
 *
 * Available variables:
 *   $payload   - array from server_tasks.payload JSON
 *   $server_id - int from server_tasks.server_id
 *
 * Return: a shell script string to be executed via `sh -c`.
 */

$source_provider = $payload['source_provider'] ?? 'github';
$repository = $payload['repository'] ?? '';
$branch = $payload['branch'] ?? 'main';
$project_id = (int) ($payload['project_id'] ?? 0);
$project_type = $payload['project_type'] ?? 'trongate_v2';
$server_ip = $payload['server_ip'] ?? '';

if (empty($repository) || empty($server_ip)) {
    return 'echo "ERROR: Missing repository or server_ip in payload" && exit 1';
}

// Build clone URL based on source provider
$clone_url = match($source_provider) {
    'github' => "https://github.com/{$repository}",
    'gitlab' => "https://gitlab.com/{$repository}",
    'gitea' => $repository, // For self-hosted, full URL is stored
    default => "https://github.com/{$repository}"
};

$escaped_clone_url = escapeshellarg($clone_url);
$escaped_branch = escapeshellarg($branch);
$escaped_ip = escapeshellarg($server_ip);

$app_dir = "/var/www/project_{$project_id}";

// Determine docker image based on project type
$docker_image = match($project_type) {
    'trongate_v1' => 'ghcr.io/trongate/trongate-v1:latest',
    'trongate_v2' => 'ghcr.io/trongate/trongate-v2:latest',
    default => 'ghcr.io/trongate/trongate-v2:latest'
};

// SSH options for non-interactive, trust new hosts
$ssh_opts = '-o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=30';

return <<<BASH
#!/bin/bash
set -e

echo "=== Starting deployment ==="
echo "Provider: {$source_provider}"
echo "Repository: {$repository}"
echo "Branch: {$branch}"
echo "Project ID: {$project_id}"
echo "Server IP: {$server_ip}"

# Wait for server to be ready (cloud-init to complete)
echo "Waiting for server to be ready..."
MAX_RETRIES=60
RETRY_COUNT=0

while [ \$RETRY_COUNT -lt \$MAX_RETRIES ]; do
    if ssh {$ssh_opts} root@{$server_ip} "test -f /root/cloud-init-complete" 2>/dev/null; then
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
CLONE_URL={$escaped_clone_url}
BRANCH={$escaped_branch}
PROJECT_ID="project_{$project_id}"
DOCKER_IMAGE="{$docker_image}"

echo "Creating application directory..."
mkdir -p \$APP_DIR
cd \$APP_DIR

# Clone or update repository
echo "Cloning repository..."
if [ -d ".git" ]; then
    echo "Repository exists, pulling latest..."
    git fetch origin
    git reset --hard origin/\$BRANCH
else
    git clone --depth 1 --branch \$BRANCH \$CLONE_URL .
fi

# Generate random passwords for database
DB_PASSWORD=\$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)
DB_ROOT_PASSWORD=\$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 32)
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
