-- Trongate Cloud: Project table
-- Migration 003

CREATE TABLE IF NOT EXISTS project (
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  label VARCHAR(255) NOT NULL,
  type ENUM('trongate_v1', 'trongate_v2') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES team(id)
);
