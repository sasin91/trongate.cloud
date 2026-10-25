-- app_backup becomes backup, which holds every backup in the backups bucket:
-- type 'app_deleted' is the one taken when an app is deleted (App_backup),
-- 'nightly' the ones taken every night (Daily_backup) of each app and of the
-- platform database (app_id NULL). Column names follow schema.org's Action
-- (startTime, endTime) and DataDownload (contentSize).
ALTER TABLE app_backup DROP FOREIGN KEY fk_app_backup_app;
RENAME TABLE app_backup TO backup;
ALTER TABLE backup
  DROP PRIMARY KEY,
  ADD id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
  ADD type ENUM('app_deleted', 'nightly') NOT NULL DEFAULT 'app_deleted' AFTER id,
  MODIFY app_id INT NULL COMMENT 'NULL: the platform database',
  MODIFY object_prefix VARCHAR(120) NOT NULL COMMENT 'folder of its objects in the backups bucket',
  ADD size_bytes BIGINT UNSIGNED NULL COMMENT 'size of what is in the bucket' AFTER object_prefix,
  MODIFY error TEXT NULL COMMENT 'why it failed, or why the app''s own backup module failed',
  CHANGE created_at started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CHANGE ready_at finished_at DATETIME NULL COMMENT 'when it was ready or failed',
  MODIFY expires_at DATETIME NULL COMMENT 'it is deleted from the bucket then',
  ADD KEY idx_app (app_id, started_at);
UPDATE backup SET size_bytes = database_bytes + files_bytes;
UPDATE backup SET finished_at = updated_at WHERE status = 'failed';
ALTER TABLE backup
  DROP COLUMN database_bytes,
  DROP COLUMN files_bytes,
  DROP COLUMN updated_at,
  MODIFY type ENUM('app_deleted', 'nightly') NOT NULL,
  ADD CONSTRAINT fk_backup_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE;
