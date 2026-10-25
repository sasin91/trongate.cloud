-- The nightly backups (Daily_backup): one row per backup of an app (its
-- database, code and persistent files, and what its own backup module adds)
-- or of the platform database (source 'platform', no app). No foreign key to
-- app, so the rows outlive a deleted app until its backups expire. Each
-- backup's key sits sealed in the backup itself.
CREATE TABLE daily_backup (
  id INT PRIMARY KEY AUTO_INCREMENT,
  source ENUM('app', 'platform') NOT NULL,
  app_id INT NULL,
  status ENUM('backing_up', 'ready', 'failed', 'expired') NOT NULL DEFAULT 'backing_up',
  object_prefix VARCHAR(120) NOT NULL COMMENT 'folder of its objects in the backups bucket (Daily_backup::new_app_prefix)',
  bytes BIGINT UNSIGNED NULL COMMENT 'size of the encrypted backup',
  error TEXT NULL COMMENT 'why it failed, or why the app''s own backup module failed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ready_at DATETIME NULL,
  expires_at DATETIME NULL COMMENT 'it is deleted from the bucket then',
  KEY idx_app (app_id, created_at),
  KEY idx_status (status, expires_at)
);
