-- Trongate Cloud: Team & Access Control tables
-- Migration 002

CREATE TABLE IF NOT EXISTS team (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  owner_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  services JSON DEFAULT '[]',
  FOREIGN KEY (owner_id) REFERENCES customer(id)
);

CREATE TABLE IF NOT EXISTS team_member (
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  customer_id INT NOT NULL,
  role VARCHAR(255) DEFAULT 'member',
  status ENUM('active', 'suspended') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES team(id),
  FOREIGN KEY (customer_id) REFERENCES customer(id),
  UNIQUE KEY unique_team_customer (team_id, customer_id)
);

-- Role definitions:
-- owner: Full control, cannot be removed
-- admin: Can manage projects, servers, members (except owner)
-- member: Can trigger deployments, view logs
-- billing: Handles subscription/payment only
