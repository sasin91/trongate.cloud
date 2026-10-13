-- .sql files imported from the Database tab (Sql_import): the browser uploads
-- them in parts (app_sql_import_chunk, encrypted with APP_KEY), the worker
-- checks every statement and, once the person runs it, runs them one by one
-- (tasks/import_sql.php). The parts are deleted once it ran, and a day after
-- an upload was abandoned or an import failed (tasks/check_sql_imports.php).
CREATE TABLE app_sql_import (
  id INT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  customer_id INT NOT NULL,
  task_id INT NULL COMMENT 'the latest import_sql task',
  log_id BIGINT NULL COMMENT 'app_database_log entry of the import',
  file_name VARCHAR(255) NOT NULL,
  bytes BIGINT UNSIGNED NOT NULL COMMENT 'the file as uploaded',
  compressed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'gzip',
  status ENUM('uploading', 'checking', 'checked', 'running', 'done', 'failed', 'stopped') NOT NULL DEFAULT 'uploading',
  sql_bytes BIGINT UNSIGNED NULL COMMENT 'the SQL, uncompressed; known once checked',
  total_statements INT UNSIGNED NULL COMMENT 'statements to run; known once checked',
  summary TEXT NULL COMMENT 'JSON: statements by first keyword, tables dropped or emptied',
  position BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'bytes of SQL checked or run',
  line INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'the line position is on',
  statement_number INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'statements up to position, run or skipped',
  statements_run INT UNSIGNED NOT NULL DEFAULT 0,
  statements_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  settings TEXT NULL COMMENT 'JSON: session settings so far (SET NAMES, ...), applied on each new connection',
  error TEXT NULL,
  can_continue TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'a failed import may carry on from the statement that failed',
  skip_first TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'carry on after the statement that failed instead',
  file_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  KEY idx_app (app_id, id),
  KEY idx_status (status, updated_at)
);

CREATE TABLE app_sql_import_chunk (
  import_id INT NOT NULL,
  seq INT UNSIGNED NOT NULL,
  bytes INT UNSIGNED NOT NULL COMMENT 'the part as uploaded',
  data MEDIUMBLOB NOT NULL COMMENT 'encrypted with APP_KEY (Sql_import::seal)',
  PRIMARY KEY (import_id, seq),
  CONSTRAINT fk_sql_import_chunk FOREIGN KEY (import_id) REFERENCES app_sql_import (id) ON DELETE CASCADE
);

ALTER TABLE app_database_log
  MODIFY action ENUM('query', 'insert', 'update', 'delete', 'restore', 'import') NOT NULL;
