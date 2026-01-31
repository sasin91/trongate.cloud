# Trongate Cloud - Deployment Platform

## Project Overview

Trongate Cloud is a deployment platform for Trongate PHP applications (v1 and v2). The platform provisions Hetzner Cloud servers on behalf of customers, deploying containerized Trongate applications with full supporting infrastructure (database, Redis, monitoring) in an isolated environment per project.

## Architecture Philosophy

- **One project per server**: Complete isolation, no noisy neighbors
- **Boring technology**: Docker, Traefik, MariaDB, Redis - proven stack
- **Predefined templates**: No custom deployment scripts from users
- **Team-based ownership**: Multi-tenant with team isolation and role-based access
- **Full observability**: Built-in Prometheus metrics and alerting per deployment

## Tech Stack

### Backend
- **Framework**: Trongate v2 (PHP)
- **Database**: MariaDB 11.2
- **Caching**: Redis (Alpine)

### Infrastructure
- **Cloud Provider**: Hetzner Cloud (hcloud API)
- **Containerization**: Docker + Docker Compose
- **Reverse Proxy**: Traefik v3.6.7
- **Monitoring**: Prometheus + Alertmanager + node-exporter
- **SSL/TLS**: Let's Encrypt via Traefik ACME

### Deployment
- **Image Registry**: GitHub Container Registry (public images)
- **Transfer Method**: rsync for application code
- **Provisioning**: Cloud-init scripts via hcloud API
- **Encryption**: libsodium for environment variables at rest

## Database Schema

### Core Entities
```sql
customer {
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
}

customer_account {
  id INT PRIMARY KEY AUTO_INCREMENT,
  customer_id INT NOT NULL,
  authenticator ENUM('password') DEFAULT 'password',
  status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
  used_at TIMESTAMP NULL,
  metadata JSON,
  FOREIGN KEY (customer_id) REFERENCES customer(id)
}

customer_logs {
  id INT PRIMARY KEY AUTO_INCREMENT,
  customer_id INT NOT NULL,
  customer_account_id INT NULL,
  subject VARCHAR(255) NOT NULL,
  description TEXT,
  user_agent VARCHAR(512),
  ip VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customer(id),
  FOREIGN KEY (customer_account_id) REFERENCES customer_account(id)
}
```

### Team & Access Control
```sql
team {
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
}

team_member {
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  customer_id INT NOT NULL,
  role ENUM('owner', 'admin', 'member', 'billing') NOT NULL,
  status ENUM('active', 'suspended') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES team(id),
  FOREIGN KEY (customer_id) REFERENCES customer(id),
  UNIQUE KEY unique_team_customer (team_id, customer_id)
}
```

**Roles**:
- `owner`: Full control, cannot be removed
- `admin`: Can manage projects, servers, members (except owner)
- `member`: Can trigger deployments, view logs
- `billing`: Handles subscription/payment only

### Projects & Infrastructure
```sql
project {
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  label VARCHAR(255) NOT NULL,
  type ENUM('trongate_v1', 'trongate_v2') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES team(id)
}

server {
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  provider VARCHAR(50) DEFAULT 'hetzner',
  type VARCHAR(50) NOT NULL, -- 'CX23', 'CAX11', etc.
  image VARCHAR(100) DEFAULT 'ubuntu-24.04',
  address VARCHAR(45) NOT NULL, -- IP address
  hcloud_id VARCHAR(50), -- Hetzner Cloud server ID
  status ENUM('provisioning', 'active', 'error', 'deleted') DEFAULT 'provisioning',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES team(id)
}

server_keypair {
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  customer_id INT NOT NULL, -- Who generated this key
  server_id INT NOT NULL,
  type ENUM('ssh_ed25519', 'ssh_rsa') DEFAULT 'ssh_ed25519',
  public_key TEXT NOT NULL,
  private_key TEXT NOT NULL, -- Encrypted at application level
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES team(id),
  FOREIGN KEY (customer_id) REFERENCES customer(id),
  FOREIGN KEY (server_id) REFERENCES server(id)
}

server_environment {
  id INT PRIMARY KEY AUTO_INCREMENT,
  server_id INT NOT NULL,
  encryption_key VARCHAR(255) NOT NULL, -- Base64 encoded libsodium key
  variables TEXT NOT NULL, -- Encrypted JSON blob
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (server_id) REFERENCES server(id)
}
```

### Deployments
```sql
deployment {
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  team_member_id INT NOT NULL, -- Who triggered this
  customer_id INT NOT NULL,
  project_id INT NOT NULL,
  server_id INT NOT NULL,
  status ENUM('pending', 'running', 'success', 'failed') DEFAULT 'pending',
  source_type ENUM('git', 'upload') NOT NULL,
  source_url VARCHAR(512), -- Git repo URL or upload path
  script TEXT, -- Generated deployment script
  output TEXT, -- Deployment logs/stdout
  exitcode INT,
  metadata JSON, -- { git_commit, docker_image_tag, checksum, etc. }
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  FOREIGN KEY (team_id) REFERENCES team(id),
  FOREIGN KEY (team_member_id) REFERENCES team_member(id),
  FOREIGN KEY (customer_id) REFERENCES customer(id),
  FOREIGN KEY (project_id) REFERENCES project(id),
  FOREIGN KEY (server_id) REFERENCES server(id),
  INDEX idx_project_status (project_id, status),
  INDEX idx_server_created (server_id, created_at)
}
```

## User Flows

### 1. Customer Onboarding
```
/ 
  → Click "Let's go"
  → /customer
    → /customer/signup (email + password)
    → /customer/signin (returning users)
  → /customer-panel (authenticated)
```

**Customer Panel States**:
- **Empty state**: "Get started by creating your first project"
- **With projects**: List of projects grouped by team

### 2. Project Creation Wizard
```
/project-wizard
  Step 1: Project Type
    → Select: trongate_v1 | trongate_v2
  
  Step 2: Server Configuration
    → Provider: Hetzner (locked for v1)
    → Server Type: CX23 (4GB, 2vCPU) | CAX11 (4GB ARM) | ...
    → Region: (dropdown from hcloud API)
  
  Step 3: Deployment Strategy
    → Git Repository (provide URL, optional branch)
    → Upload (zip file)
  
  Step 4: Environment Variables (optional)
    → Key-value pairs for custom env vars
    → Merged with generated DB credentials
  
  Step 5: Review & Deploy
    → Show cost estimate
    → Confirm → triggers provisioning
```

### 3. Deployment Flow (Backend)
```
1. Create deployment record (status: pending)
2. Provision Hetzner server via hcloud API
   - Create server with cloud-init
   - Wait for server to be running
   - Store IP in server.address
3. Generate SSH keypair
   - Store in server_keypair (encrypt private key)
4. Generate environment variables
   - DB credentials (random)
   - Redis config
   - Application vars from wizard
   - Encrypt and store in server_environment
5. Generate docker-compose.yml from template
   - Substitute {{PROJECT_ID}}, {{DOMAIN}}, etc.
6. Execute deployment script
   - rsync docker-compose.yml + .env
   - rsync application code
   - SSH: docker-compose pull && docker-compose up -d
7. Update deployment record
   - Store script, output, exitcode
   - Set status: success | failed
   - Record metadata (git commit, timestamps)
```

## Docker Compose Template

Base template for all deployments (parameterized):
```yaml
version: '3'

services:
  ${PROJECT_ID}_traefik:
    image: traefik:v3.6.7
    container_name: ${PROJECT_ID}_traefik
    networks:
      - ${PROJECT_ID}_net
    command:
      - "--api.insecure=false"
      - "--providers.docker=true"
      - "--entrypoints.web.address=:80"
      - "--entrypoints.websecure.address=:443"
      - "--certificatesresolvers.letsencrypt.acme.tlschallenge=true"
      - "--certificatesresolvers.letsencrypt.acme.email=${ACME_EMAIL}"
      - "--certificatesresolvers.letsencrypt.acme.storage=/letsencrypt/acme.json"
      - "--metrics.prometheus=true"
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - "/var/run/docker.sock:/var/run/docker.sock:ro"
      - "./letsencrypt:/letsencrypt"
    deploy:
      resources:
        limits:
          cpus: '0.5'
          memory: 128M

  ${PROJECT_ID}_app:
    image: ghcr.io/trongate/trongate-${PROJECT_TYPE}:latest
    container_name: ${PROJECT_ID}_app
    environment:
      - DB_HOST=${PROJECT_ID}_db
      - DB_USER=${DB_USER}
      - DB_PASSWORD=${DB_PASSWORD}
      - DB_NAME=${DB_NAME}
      - ENV=production
      - BASE_URL=https://${DOMAIN}/
      - REDIS_HOST=${PROJECT_ID}_redis
      - REDIS_PORT=6379
    depends_on:
      - ${PROJECT_ID}_db
      - ${PROJECT_ID}_redis
    networks:
      - ${PROJECT_ID}_net
    volumes:
      - /var/www/${PROJECT_ID}/app:/var/www/html
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.${PROJECT_ID}_app.rule=Host(`${DOMAIN}`)"
      - "traefik.http.routers.${PROJECT_ID}_app.entrypoints=websecure"
      - "traefik.http.routers.${PROJECT_ID}_app.tls.certresolver=letsencrypt"
      - "traefik.http.services.${PROJECT_ID}_app.loadbalancer.server.port=80"
    deploy:
      resources:
        limits:
          cpus: '1.5'
          memory: 1536M
        reservations:
          memory: 512M

  ${PROJECT_ID}_db:
    image: mariadb:11.2
    container_name: ${PROJECT_ID}_db
    environment:
      - MYSQL_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
      - MYSQL_DATABASE=${DB_NAME}
      - MYSQL_USER=${DB_USER}
      - MYSQL_PASSWORD=${DB_PASSWORD}
      - MARIADB_BUFFER_POOL_SIZE=256M
      - MARIADB_MAX_CONNECTIONS=50
    volumes:
      - db_data:/var/lib/mysql
    networks:
      - ${PROJECT_ID}_net
    deploy:
      resources:
        limits:
          cpus: '1.0'
          memory: 512M

  ${PROJECT_ID}_redis:
    image: redis:alpine
    container_name: ${PROJECT_ID}_redis
    networks:
      - ${PROJECT_ID}_net
    deploy:
      resources:
        limits:
          cpus: '0.25'
          memory: 128M

  ${PROJECT_ID}_node-exporter:
    image: prom/node-exporter:latest
    container_name: ${PROJECT_ID}_node-exporter
    networks:
      - ${PROJECT_ID}_net
    ports:
      - "9100:9100"
    command:
      - '--path.rootfs=/host'
    volumes:
      - /:/host:ro,rslave
    deploy:
      resources:
        limits:
          cpus: '0.1'
          memory: 64M

  ${PROJECT_ID}_prometheus:
    image: prom/prometheus:latest
    container_name: ${PROJECT_ID}_prometheus
    networks:
      - ${PROJECT_ID}_net
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml:ro
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.path=/prometheus'
      - '--web.console.libraries=/usr/share/prometheus/console_libraries'
      - '--web.console.templates=/usr/share/prometheus/consoles'
    deploy:
      resources:
        limits:
          cpus: '0.5'
          memory: 256M

networks:
  ${PROJECT_ID}_net:
    driver: bridge

volumes:
  db_data:
```

**Template Variables**:
- `${PROJECT_ID}`: Unique project identifier (e.g., `proj_abc123`)
- `${PROJECT_TYPE}`: `v1` or `v2`
- `${DOMAIN}`: Customer's domain (e.g., `myapp.com`)
- `${ACME_EMAIL}`: Email for Let's Encrypt
- `${DB_ROOT_PASSWORD}`: Generated secure password
- `${DB_USER}`: Generated username
- `${DB_PASSWORD}`: Generated secure password
- `${DB_NAME}`: Database name

## Encryption Strategy

### Environment Variables

Using libsodium for symmetric encryption:
```php
// Generate encryption key (store in server_environment.encryption_key)
$encryption_key = sodium_crypto_secretbox_keygen(); // 32 bytes
$encryption_key_base64 = base64_encode($encryption_key);

// Encrypt variables
$variables = ['DB_PASSWORD' => 'secret123', 'API_KEY' => 'xyz'];
$variables_json = json_encode($variables);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); // 24 bytes
$ciphertext = sodium_crypto_secretbox($variables_json, $nonce, $encryption_key);
$encrypted_blob = base64_encode($nonce . $ciphertext);

// Store $encrypted_blob in server_environment.variables

// Decrypt when needed
$decoded = base64_decode($encrypted_blob);
$nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$variables_json = sodium_crypto_secretbox_open($ciphertext, $nonce, $encryption_key);
$variables = json_decode($variables_json, true);
```

### SSH Private Keys

Private keys in `server_keypair.private_key` are encrypted using the same method before storage. Decrypted only when needed for deployments.

## Hetzner Cloud Integration

### Server Provisioning
```php
// Using hcloud API v1
$hcloud_token = getenv('HCLOUD_TOKEN');

// 1. Create SSH key
$keypair = sodium_crypto_sign_keypair();
$public_key = sodium_crypto_sign_publickey($keypair);
$private_key = sodium_crypto_sign_secretkey($keypair);

// Upload SSH key to Hetzner
POST https://api.hetzner.cloud/v1/ssh_keys
{
  "name": "project_{$project_id}_key",
  "public_key": "{$public_key}"
}

// 2. Create server with cloud-init
POST https://api.hetzner.cloud/v1/servers
{
  "name": "tcloud-{$project_id}",
  "server_type": "cx23", // or from user selection
  "location": "nbg1", // Nuremberg
  "image": "ubuntu-24.04",
  "ssh_keys": [ssh_key_id],
  "user_data": "{$cloud_init_script}",
  "labels": {
    "project_id": "{$project_id}",
    "team_id": "{$team_id}",
    "managed_by": "trongate-cloud"
  }
}

// 3. Wait for server to be running
GET https://api.hetzner.cloud/v1/servers/{server_id}
// Poll until status === 'running'

// 4. Store server info
UPDATE server SET 
  hcloud_id = {server_id},
  address = {public_ipv4},
  status = 'active'
WHERE id = {internal_server_id}
```

### Cloud-Init Script
```yaml
#cloud-config

packages:
  - docker.io
  - docker-compose-v2

runcmd:
  - systemctl enable docker
  - systemctl start docker
  - docker network create web
  - mkdir -p /var/www
  - curl -fsSL https://download.docker.com/linux/ubuntu/gpg | apt-key add -
```

## Deployment Script Template

Generated per deployment, stored in `deployment.script`:
```bash
#!/bin/bash
set -euo pipefail

# Generated by Trongate Cloud
# Deployment ID: {{DEPLOYMENT_ID}}
# Project: {{PROJECT_LABEL}}
# Date: {{TIMESTAMP}}

PROJECT_ID="{{PROJECT_ID}}"
SERVER_IP="{{SERVER_IP}}"
DOMAIN="{{DOMAIN}}"
SSH_KEY_PATH="/tmp/deploy_key_{{DEPLOYMENT_ID}}"

# Write SSH key (decrypted from DB)
cat > ${SSH_KEY_PATH} <<'SSHKEY'
{{PRIVATE_KEY}}
SSHKEY
chmod 600 ${SSH_KEY_PATH}

# Generate .env file
cat > /tmp/${PROJECT_ID}.env <<'ENVFILE'
{{ENVIRONMENT_VARIABLES}}
ENVFILE

# Generate docker-compose.yml
cat > /tmp/${PROJECT_ID}-compose.yml <<'COMPOSEFILE'
{{DOCKER_COMPOSE_CONTENT}}
COMPOSEFILE

# rsync application code
echo "Syncing application code..."
rsync -avz --delete \
  -e "ssh -i ${SSH_KEY_PATH} -o StrictHostKeyChecking=no" \
  {{SOURCE_PATH}}/ \
  root@${SERVER_IP}:/var/www/${PROJECT_ID}/app/

# rsync docker-compose + .env
echo "Deploying configuration..."
rsync -avz \
  -e "ssh -i ${SSH_KEY_PATH} -o StrictHostKeyChecking=no" \
  /tmp/${PROJECT_ID}-compose.yml \
  root@${SERVER_IP}:/var/www/${PROJECT_ID}/docker-compose.yml

rsync -avz \
  -e "ssh -i ${SSH_KEY_PATH} -o StrictHostKeyChecking=no" \
  /tmp/${PROJECT_ID}.env \
  root@${SERVER_IP}:/var/www/${PROJECT_ID}/.env

# Deploy containers
echo "Starting containers..."
ssh -i ${SSH_KEY_PATH} -o StrictHostKeyChecking=no root@${SERVER_IP} << 'REMOTECMD'
cd /var/www/{{PROJECT_ID}}
docker-compose pull
docker-compose up -d
docker-compose ps
REMOTECMD

echo "Deployment complete!"
echo "Application available at: https://{{DOMAIN}}"

# Cleanup
rm -f ${SSH_KEY_PATH}
rm -f /tmp/${PROJECT_ID}.env
rm -f /tmp/${PROJECT_ID}-compose.yml
```

## Monitoring & Observability

### Per-Deployment Metrics

Each deployment includes:
- **node-exporter**: System metrics (CPU, RAM, disk, network)
- **Prometheus**: Scrapes Traefik, node-exporter, custom app metrics
- **Alertmanager**: Handles alert routing

### Central Aggregation (Future)

Prometheus on each server can `remote_write` to central instance:
```yaml
# prometheus.yml on deployed server
remote_write:
  - url: https://metrics.trongate.cloud/api/v1/write
    basic_auth:
      username: {{METRICS_USER}}
      password: {{METRICS_PASSWORD}}
```

### Key Metrics to Track

- Container health (up/down)
- HTTP request rate/latency (Traefik)
- Database connections/slow queries
- Redis memory usage
- Disk space (especially `/var/lib/docker`)

## Security Considerations

1. **SSH Keys**: Generated per server, private keys encrypted at rest
2. **Environment Variables**: Encrypted using libsodium, decrypted only during deployment
3. **Database Passwords**: Generated using `sodium_bin2hex(random_bytes(32))`
4. **API Tokens**: Hetzner API token stored in environment, never in database
5. **Container Isolation**: Each project gets own network namespace
6. **TLS**: Automatic via Let's Encrypt, enforced by Traefik
7. **Audit Logging**: All actions logged to `customer_logs` with IP/user-agent

## Resource Limits

### Server Types

| Type  | vCPU | RAM  | Disk   | Price/mo | Use Case |
|-------|------|------|--------|----------|----------|
| CX23  | 2    | 4GB  | 40GB   | €5.83    | Default  |
| CAX11 | 2    | 4GB  | 40GB   | €4.15    | ARM budget |
| CX33  | 2    | 8GB  | 80GB   | €11.96   | High traffic |

### Container Limits (CX23 baseline)

- **Traefik**: 0.5 CPU, 128MB RAM
- **App**: 1.5 CPU, 1536MB RAM (1GB reserved)
- **MariaDB**: 1.0 CPU, 512MB RAM
- **Redis**: 0.25 CPU, 128MB RAM
- **Prometheus**: 0.5 CPU, 256MB RAM
- **node-exporter**: 0.1 CPU, 64MB RAM

**Total**: ~3 CPU, ~2.6GB RAM (leaves headroom on 4GB server)

## Rollback Strategy

To rollback a deployment:

1. Find previous successful deployment: 
```sql
   SELECT * FROM deployment 
   WHERE project_id = ? AND status = 'success' 
   ORDER BY created_at DESC LIMIT 2
```

2. Create new deployment record copying previous:
   - `source_type`, `source_url` from old deployment
   - `metadata.git_commit` if applicable

3. Execute deployment normally (idempotent)

## Backup Strategy

### Database Backups

Daily automated backups via cron on each server:
```bash
# /etc/cron.daily/backup-db
docker exec ${PROJECT_ID}_db \
  mysqldump -u root -p${DB_ROOT_PASSWORD} ${DB_NAME} | \
  gzip > /var/backups/db-$(date +%Y%m%d).sql.gz

# Retain 7 days
find /var/backups -name "db-*.sql.gz" -mtime +7 -delete
```

### Volume Snapshots

Use Hetzner Volume snapshots (manual or API-triggered):
```php
POST https://api.hetzner.cloud/v1/volumes/{volume_id}/actions/snapshot
{
  "description": "backup-{$project_id}-{$timestamp}",
  "labels": {
    "project_id": "{$project_id}"
  }
}
```

## Cost Model

### Infrastructure Costs (per project)

- **Server**: €5.83/month (CX23)
- **Volume**: €0.0476/GB/month (40GB = €1.90)
- **Traffic**: First 20TB free
- **Backups**: €0.0119/GB/month per snapshot

**Base cost per project**: ~€8/month

### Pricing Tiers (Proposed)
TBD, start free

Target profit margin: ~50-85% depending on tier

## Development Roadmap

### Phase 1: MVP (Current)
- [ ] Database schema implementation
- [ ] Customer authentication (password-only)
- [ ] Team & project management
- [ ] Hetzner server provisioning
- [ ] Basic deployment (git source)
- [ ] Docker compose template system
- [ ] Environment variable encryption

### Phase 2: Core Features
- [ ] Upload-based deployment (zip)
- [ ] Deployment history & rollback
- [ ] Real-time deployment logs (WebSocket)
- [ ] DNS management (point domain to server)
- [ ] Billing integration (Stripe)
- [ ] Email notifications (deployment status)

### Phase 3: Enhanced Observability
- [ ] Central metrics aggregation
- [ ] Alert routing to customer email/Slack
- [ ] Uptime monitoring
- [ ] Performance dashboards
- [ ] Log aggregation (Loki?)

### Phase 4: Advanced Features
- [ ] Multi-region deployments
- [ ] Load balancer support (multiple servers per project)
- [ ] Staging environments
- [ ] Auto-scaling (horizontal)
- [ ] Database replication
- [ ] CDN integration (BunnyCDN?)

## File Structure
```
trongate-cloud/
├── config/                         # Framework configuration
│   ├── config.php
│   ├── database.php
│   └── routes.php
├── db/                             # Database migrations (load in order)
│   ├── 001_customers.sql
│   ├── 002_teams.sql
│   ├── 003_projects.sql
│   ├── 004_servers.sql
│   └── 005_deployments.sql
├── engine/                         # Trongate core (do not modify)
├── modules/                        # Application modules
│   ├── customers/
│   │   ├── Customers.php           # Controller
│   │   ├── Customers_model.php     # Model
│   │   └── views/
│   │       ├── signup.php
│   │       ├── signin.php
│   │       └── panel.php
│   ├── teams/
│   │   ├── Teams.php
│   │   ├── Teams_model.php
│   │   └── views/
│   │       ├── create.php
│   │       ├── manage.php
│   │       └── members.php
│   ├── projects/
│   │   ├── Projects.php
│   │   ├── Projects_model.php
│   │   └── views/
│   │       ├── wizard.php
│   │       ├── show.php
│   │       └── list.php
│   ├── servers/
│   │   ├── Servers.php
│   │   ├── Servers_model.php
│   │   └── views/
│   │       ├── show.php
│   │       └── environment.php
│   ├── deployments/
│   │   ├── Deployments.php
│   │   ├── Deployments_model.php
│   │   ├── views/
│   │   │   ├── show.php
│   │   │   ├── logs.php
│   │   │   └── history.php
│   │   └── scripts/
│   │       ├── docker-compose/
│   │       │   ├── trongate_v1.yml
│   │       │   └── trongate_v2.yml
│   │       ├── cloud-init/
│   │       │   └── ubuntu-24.04.yml
│   │       └── deploy.sh.template
│   ├── hcloud/                     # Hetzner Cloud API integration
│   │   ├── Hcloud.php
│   │   └── Hcloud_model.php
│   ├── encryption/                 # libsodium wrapper
│   │   └── Encryption.php
│   ├── deployment_engine/          # Core deployment logic
│   │   ├── Deployment_engine.php
│   │   └── Deployment_engine_model.php
│   └── templates/                  # Template rendering (extends framework)
│       ├── Templates.php
│       └── views/
│           ├── admin.php
│           └── public.php
├── public/                         # Web root & static assets
│   ├── index.php
│   ├── css/
│   └── js/
└── docker/                         # Local development / monitoring configs
    ├── prometheus/
    │   └── prometheus.yml
    └── alertmanager/
        └── alertmanager.yml
```

## Environment Variables
```bash
# Hetzner Cloud
HCLOUD_TOKEN=your_hetzner_api_token

# Database
DB_HOST=localhost
DB_USER=tcloud_user
DB_PASSWORD=secure_password
DB_NAME=trongate_cloud

# Redis
REDIS_HOST=localhost
REDIS_PORT=6379

# Application
ENV=production
BASE_URL=https://trongate.cloud/
DEPLOYMENT_WORK_DIR=/var/tcloud/deployments

# Encryption (generate with sodium_crypto_secretbox_keygen())
MASTER_ENCRYPTION_KEY=base64_encoded_key_here

# Image Registry
REGISTRY_URL=ghcr.io/trongate
REGISTRY_USERNAME=github_username
REGISTRY_TOKEN=github_pat_token
```

## Testing Strategy

### Unit Tests
- Encryption/decryption functions
- Template variable substitution
- Password generation
- Team permission checks

### Integration Tests
- Hetzner API mocking
- Full deployment flow (staging)
- Rollback scenarios
- Multi-team isolation

### Manual QA Checklist
- [ ] Create account
- [ ] Create team
- [ ] Add team member
- [ ] Create project (v1 and v2)
- [ ] Trigger deployment from git
- [ ] Verify HTTPS works
- [ ] Check Prometheus metrics
- [ ] Test rollback
- [ ] Delete project (cleanup)

## Known Limitations & Trade-offs

1. **One project per server**: Higher cost than shared hosting, but simpler isolation
2. **No live migration**: Changing server type requires new deployment
3. **SSH-based deployment**: Not real-time, but proven and debuggable
4. **In-DB encryption keys**: Not HSM-level security, but pragmatic for MVP
5. **No automatic scaling**: Manual server resize for now
6. **Hetzner-only**: Vendor lock-in, but can add providers later

## Support & Maintenance

### Monitoring Platform Health
- Hetzner API rate limits (watch for 429s)
- Deployment queue depth
- Failed deployment rate
- Customer support tickets (future)

### Regular Maintenance
- Update Docker images (monthly)
- Security patches (as needed)
- Database backups verification (weekly)
- Cost monitoring (daily)

## Questions & Decisions Needed

- [ ] Domain registration included or BYOD (bring your own domain)?
- [ ] Support for custom Docker images or locked to official Trongate images?
- [ ] Database migration tools (Phinx, Doctrine) or manual?
- [ ] Multi-stage deployments (dev → staging → production)?
- [ ] Support for non-Trongate PHP apps (future)?

---

**Document Version**: 1.0  
**Last Updated**: 2026-01-31  
**Maintained By**: Jonas (jonas@trongate.cloud)