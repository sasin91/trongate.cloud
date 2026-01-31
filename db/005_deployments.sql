-- Trongate Cloud: Deployment table
-- Migration 005

CREATE TABLE IF NOT EXISTS deployment (
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
);
