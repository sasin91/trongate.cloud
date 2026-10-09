-- Trongate upgrades (modules/platform/tasks/upgrade_app.php): each run finds
-- the release an app is on and opens a pull request moving it to the newest.
CREATE TABLE IF NOT EXISTS app_upgrade (
  id INT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  -- Who asked; NULL for admin (MCP)
  customer_id INT NULL,
  -- opened: a pull request is open; current: already on the newest release
  status ENUM('pending', 'running', 'opened', 'current', 'failed') NOT NULL DEFAULT 'pending',
  from_version VARCHAR(20) NULL,
  to_version VARCHAR(20) NULL,
  pr_url VARCHAR(500) NULL,
  -- Upgrade_plan::counts() as JSON
  summary TEXT NULL,
  error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_app (app_id, id),
  CONSTRAINT fk_app_upgrade_app FOREIGN KEY (app_id) REFERENCES app (id),
  CONSTRAINT fk_app_upgrade_customer FOREIGN KEY (customer_id) REFERENCES customer (id)
);
