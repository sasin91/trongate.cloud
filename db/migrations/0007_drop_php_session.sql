-- Panel sessions live in Valkey since #56 (engine/Valkey_sessions.php), so the
-- MariaDB session table is unused.
DROP TABLE IF EXISTS php_session;
