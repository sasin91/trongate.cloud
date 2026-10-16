-- Showing an app's database password in the Database tab's Credentials dialog
-- is logged too, so History says who saw it.
ALTER TABLE app_database_log
  MODIFY action ENUM('query', 'insert', 'update', 'delete', 'restore', 'import', 'credentials') NOT NULL;
