-- Trongate Cloud: Server infrastructure tables
-- Migration 004

CREATE TABLE server (
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  provider VARCHAR(50) NOT NULL, -- 'hetzner', 'linode', 'digitalocean'
  provider_id VARCHAR(50), -- Provider's server ID
  type VARCHAR(50) NOT NULL, -- 'cx23', 's-1vcpu-1gb', etc.
  region VARCHAR(50) NOT NULL, -- 'nbg1', 'us-east', etc.
  image VARCHAR(100) DEFAULT 'ubuntu-24.04',
  address VARCHAR(45), -- IP address (assigned after provisioning)
  status ENUM('provisioning', 'active', 'stopped', 'error', 'deleted') DEFAULT 'provisioning',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES team(id),
  INDEX idx_provider (provider, provider_id)
);

CREATE TABLE server_keypair (
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
);

-- Note: Server environment variables stored in `secrets` table (see 008_secrets.sql)
-- Usage: entity_type='server', entity_id=server.id
