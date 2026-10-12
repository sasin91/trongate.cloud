-- The SQL a new app's database is set up with, chosen in the new-app wizard
-- (Setup_sql). The first deploy runs it right after the database is created
-- (Platform_task::run_setup_sql). Encrypted with APP_KEY (Setup_sql::encrypt)
-- and cleared once it ran; when a statement fails it keeps the statements
-- from that one on.
ALTER TABLE app
  ADD COLUMN setup_sql LONGTEXT NULL AFTER health_path,
  ADD COLUMN setup_sql_status ENUM('pending', 'done', 'failed') NULL AFTER setup_sql,
  ADD COLUMN setup_sql_error TEXT NULL AFTER setup_sql_status;
