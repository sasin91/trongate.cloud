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

[
  'source_provider' => $source_provider,
  'source_url' => $source_url,
  'source_token' => $source_token,
  'source_branch' => $source_branch,
  'project_id' => $project_id,
  'project_type' => $project_type,
  'server_ip' => $server_ip,
  'domain' => $domain
] = $payload;

/** @var Github $source_service */
$source_service = Modules::run('services/' . $source_provider, $source_token);
$tarball_api_url = $source_service->download_url($source_url, $source_branch);

$escaped_tarball_url = escapeshellarg($tarball_api_url);
$escaped_token = escapeshellarg($source_token);
$escaped_branch = escapeshellarg($source_branch);
$escaped_domain = escapeshellarg($domain);

$app_dir = "/var/www/project_{$project_id}";

// Determine docker image based on project type
$docker_image = "php:8.2-apache-trixie";

// SSH options for non-interactive, trust new hosts
// Uses platform CA-signed certificate mounted at /root/.ssh/id_ed25519
$ssh_opts = '-i /root/.ssh/id_ed25519 -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=30';

$bash_start = <<<BASH
#!/bin/bash
set -e

echo "=== Starting deployment ==="
echo "Provider: {$source_provider}"
echo "Repository: {$repo}"
echo "Branch: {$source_branch}"
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
DOMAIN={$escaped_domain}

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
BASH;

// Prepare environment configuration from repository
$env_setup = <<<BASH
# Setup environment from repository
echo "Configuring environment..."
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        echo "Creating .env from .env.example..."
        cp .env.example .env
    else
        echo "ERROR: No .env or .env.example found in repository"
        echo "Please ensure your repository contains database configuration"
        exit 1
    fi
fi

BASH;

// Generate docker-compose.yml based on domain presence
if (empty($domain)):
    // No custom domain - HTTP only (nip.io fallback)
    $docker_compose_block = <<<BASH
SERVER_IP=\$(hostname -I | awk '{print \$1}')
EFFECTIVE_DOMAIN="\${SERVER_IP}.nip.io"

# Update .env with deployment-specific values
echo "Updating .env with domain and project settings..."
sed -i "s|^DOMAIN=.*|DOMAIN=\${EFFECTIVE_DOMAIN}|" .env || echo "DOMAIN=\${EFFECTIVE_DOMAIN}" >> .env
sed -i "s|^BASE_URL=.*|BASE_URL=http://\${EFFECTIVE_DOMAIN}/|" .env || echo "BASE_URL=http://\${EFFECTIVE_DOMAIN}/" >> .env
sed -i "s|^ENV=.*|ENV=production|" .env || echo "ENV=production" >> .env

cat > docker-compose.yml << EOF
services:
  traefik:
    image: traefik:v3.6.7
    container_name: \${PROJECT_ID}_traefik
    command:
      - "--api.insecure=true"
      - "--providers.docker=true"
      - "--providers.docker.exposedbydefault=false"
      - "--entrypoints.web.address=:80"
    ports:
      - "80:80"
      - "8080:8080"
    volumes:
      - "/var/run/docker.sock:/var/run/docker.sock:ro"
    restart: unless-stopped

  app:
    image: \${DOCKER_IMAGE}
    container_name: \${PROJECT_ID}_app
    env_file:
      - .env
    depends_on:
      - db
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.\${PROJECT_ID}_app.rule=Host(\`\${EFFECTIVE_DOMAIN}\`)"
      - "traefik.http.services.\${PROJECT_ID}_app.loadbalancer.server.port=80"
    volumes:
      - ./:/var/www/html

  db:
    image: mariadb:11.2
    container_name: \${PROJECT_ID}_db
    env_file:
      - .env
    volumes:
      - db_data:/var/lib/mysql

volumes:
  db_data:
EOF
BASH;
else:
    // Custom domain - HTTPS/TLS Enabled
    $docker_compose_block = <<<BASH
EFFECTIVE_DOMAIN={$escaped_domain}

# Update .env with deployment-specific values
echo "Updating .env with domain and project settings..."
sed -i "s|^DOMAIN=.*|DOMAIN=\${EFFECTIVE_DOMAIN}|" .env || echo "DOMAIN=\${EFFECTIVE_DOMAIN}" >> .env
sed -i "s|^BASE_URL=.*|BASE_URL=https://\${EFFECTIVE_DOMAIN}/|" .env || echo "BASE_URL=https://\${EFFECTIVE_DOMAIN}/" >> .env
sed -i "s|^ENV=.*|ENV=production|" .env || echo "ENV=production" >> .env

cat > docker-compose.yml << EOF
services:
  traefik:
    image: traefik:v3.6.7
    container_name: \${PROJECT_ID}_traefik
    command:
      - "--api.insecure=true"
      - "--providers.docker=true"
      - "--providers.docker.exposedbydefault=false"
      - "--entrypoints.web.address=:80"
      - "--entrypoints.web.http.redirections.entryPoint.to=websecure"
      - "--entrypoints.web.http.redirections.entryPoint.scheme=https"
      - "--entrypoints.websecure.address=:443"
      - "--certificatesresolvers.le.acme.email=admin@example.com"
      - "--certificatesresolvers.le.acme.storage=/letsencrypt/acme.json"
      - "--certificatesresolvers.le.acme.httpchallenge.entrypoint=web"
    ports:
      - "80:80"
      - "443:443"
      - "8080:8080"
    volumes:
      - "/var/run/docker.sock:/var/run/docker.sock:ro"
      - "traefik_certs:/letsencrypt"
    restart: unless-stopped

  app:
    image: \${DOCKER_IMAGE}
    container_name: \${PROJECT_ID}_app
    env_file:
      - .env
    depends_on:
      - db
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.\${PROJECT_ID}_app.rule=Host(\`\${EFFECTIVE_DOMAIN}\`)"
      - "traefik.http.routers.\${PROJECT_ID}_app.entrypoints=websecure"
      - "traefik.http.routers.\${PROJECT_ID}_app.tls=true"
      - "traefik.http.routers.\${PROJECT_ID}_app.tls.certresolver=le"
      - "traefik.http.services.\${PROJECT_ID}_app.loadbalancer.server.port=80"
    volumes:
      - ./:/var/www/html

  db:
    image: mariadb:11.2
    container_name: \${PROJECT_ID}_db
    env_file:
      - .env
    volumes:
      - db_data:/var/lib/mysql

volumes:
  db_data:
  traefik_certs:
EOF
BASH;
endif;

$bash_end = <<<BASH

# Start containers
echo "Starting Docker containers..."

echo "Using base configuration"
docker compose pull
docker compose up -d

echo "=== Deployment complete ==="
echo "Application deployed to: \${BASE_URL}"
REMOTE_SCRIPT

echo "=== Deployment finished successfully ==="
BASH;

return $bash_start . "\n" . $env_setup . "\n" . $docker_compose_block . "\n" . $bash_end;
