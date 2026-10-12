-- The SQL a new app's database starts with, chosen in the new-app wizard
-- (Starting_sql). The first deploy runs it right after the database is
-- created (Platform_task::run_starting_sql) and clears it; when a statement
-- fails it keeps the statements from that one on, and why.
ALTER TABLE app
  ADD COLUMN starting_sql MEDIUMTEXT NULL AFTER health_path,
  ADD COLUMN starting_sql_error TEXT NULL AFTER starting_sql;
