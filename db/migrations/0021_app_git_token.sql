-- trongate.cloud git (modules/git): apps whose source_provider is 'internal'
-- build from a bare repository on the platform-git volume. Members clone and
-- push it over HTTPS with an access token; only its SHA-256 is kept.
-- 'push' is a deploy queued by a push to that repository.
CREATE TABLE app_git_token (
  id INT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL COMMENT 'the app whose repository it opens (p{app_id}.git)',
  customer_id INT NOT NULL COMMENT 'who made it; it stops working once they leave the project',
  label VARCHAR(60) NOT NULL DEFAULT '',
  token_hash CHAR(64) NOT NULL,
  last_four CHAR(4) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL DEFAULT NULL,
  revoked_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY unique_token_hash (token_hash),
  KEY idx_app (app_id),
  CONSTRAINT fk_app_git_token_app FOREIGN KEY (app_id) REFERENCES app (id),
  CONSTRAINT fk_app_git_token_customer FOREIGN KEY (customer_id) REFERENCES customer (id)
);

ALTER TABLE deployment
  MODIFY trigger_type ENUM('manual', 'webhook', 'mcp', 'rollback', 'reconcile', 'platform', 'push') NOT NULL DEFAULT 'manual';
