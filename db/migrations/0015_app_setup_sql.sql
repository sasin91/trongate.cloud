-- The SQL a new app's database is set up with leaves the app table for
-- app_setup_sql, one row per app that had some: app.setup_sql becomes
-- statements, setup_sql_status status and setup_sql_error error. The values
-- are copied, then the app columns dropped.
CREATE TABLE app_setup_sql (
  app_id INT PRIMARY KEY,
  statements LONGTEXT NULL,
  status ENUM('pending', 'done', 'failed', 'dismissed') NOT NULL DEFAULT 'pending',
  error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_setup_sql_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE
);

INSERT INTO app_setup_sql (app_id, statements, status, error)
SELECT id, setup_sql, setup_sql_status, setup_sql_error
FROM app
WHERE setup_sql_status IS NOT NULL;

ALTER TABLE app
  DROP COLUMN setup_sql,
  DROP COLUMN setup_sql_status,
  DROP COLUMN setup_sql_error;
