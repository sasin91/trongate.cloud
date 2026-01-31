-- Trongate Cloud: Server infrastructure tables
-- Migration 004

CREATE TABLE IF NOT EXISTS server (
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
);

CREATE TABLE IF NOT EXISTS server_keypair (
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

CREATE TABLE IF NOT EXISTS server_environment (
  id INT PRIMARY KEY AUTO_INCREMENT,
  server_id INT NOT NULL,
  encryption_key VARCHAR(255) NOT NULL, -- Base64 encoded libsodium key
  variables TEXT NOT NULL, -- Encrypted JSON blob
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (server_id) REFERENCES server(id)
);
