-- Deleting an app backs up its database and files first (App_backup): one row
-- per deleted app, while the backup is taken and for as long as its project
-- can download it.
CREATE TABLE app_backup (
  app_id INT PRIMARY KEY,
  status ENUM('backing_up', 'ready', 'failed', 'expired') NOT NULL DEFAULT 'backing_up',
  object_prefix VARCHAR(120) NOT NULL COMMENT 'folder of its objects in the backups bucket (App_backup::new_prefix)',
  database_bytes BIGINT UNSIGNED NULL,
  files_bytes BIGINT UNSIGNED NULL,
  error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  ready_at DATETIME NULL,
  expires_at DATETIME NULL COMMENT 'downloads end and the objects are deleted',
  KEY idx_status (status, expires_at),
  CONSTRAINT fk_app_backup_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE
);
