-- A failed setup SQL the customer dismissed from the app page: its SQL is
-- cleared, its error kept
ALTER TABLE app MODIFY setup_sql_status ENUM('pending', 'done', 'failed', 'dismissed') NULL;
