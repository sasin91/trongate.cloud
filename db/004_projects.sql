-- Trongate Cloud: Project table
-- Migration 004

CREATE TABLE IF NOT EXISTS project (
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  label VARCHAR(255) NOT NULL,
  type ENUM('trongate_v1', 'trongate_v2') NOT NULL,
  domain VARCHAR(255) NULL COMMENT 'Custom domain for this project (optional)',
  source_url VARCHAR(500) NULL,
  source_branch VARCHAR(100) DEFAULT 'main',
  source_provider VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES team(id)
);
