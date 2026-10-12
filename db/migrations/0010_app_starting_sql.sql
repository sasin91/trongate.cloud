-- The SQL a new app's database starts with, chosen in the new-app wizard
-- (Starting_sql). The first deploy runs it once, right after the database
-- is created (Platform_task::run_starting_sql), and records how it went.
CREATE TABLE app_starting_sql (
  id INT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  -- What it came from, e.g. "db/schema.sql, typed SQL"
  sources VARCHAR(2000) NOT NULL DEFAULT '',
  sql_text MEDIUMTEXT NOT NULL,
  status ENUM('pending', 'done', 'failed') NOT NULL DEFAULT 'pending',
  -- Why it stopped, when it failed
  message TEXT NULL,
  -- The statements from the failed one on, to run by hand
  remaining_sql MEDIUMTEXT NULL,
  ran_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_app_starting_sql_app (app_id),
  CONSTRAINT fk_app_starting_sql_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE
);
