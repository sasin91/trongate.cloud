-- How an app is built and run moves out of the app table into app_runtime,
-- one row per app: its image build (PHP extensions, build commands) and its
-- runtime settings. The values are copied (mail_capture as captures_mail,
-- health_path as health_check_path), then the app columns dropped.
CREATE TABLE app_runtime (
  app_id INT PRIMARY KEY,
  php_extensions JSON NOT NULL DEFAULT '[]',
  build_commands TEXT NOT NULL DEFAULT '',
  persistent_paths JSON NOT NULL DEFAULT '[]',
  always_on TINYINT(1) NOT NULL DEFAULT 0,
  trongate_env VARCHAR(20) NOT NULL DEFAULT 'dev',
  captures_mail TINYINT(1) NOT NULL DEFAULT 1,
  health_check_path VARCHAR(200) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_runtime_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE
);

-- app.php_extensions is a list like "imagick,sqlsrv" (older values may hold
-- spaces): split it into names and store them as a JSON list
INSERT INTO app_runtime (app_id, php_extensions, build_commands, persistent_paths, always_on, trongate_env, captures_mail, health_check_path)
WITH RECURSIVE split (app_id, name, rest) AS (
  SELECT id, CAST('' AS CHAR(255)), CAST(CONCAT(REPLACE(LOWER(TRIM(php_extensions)), ' ', ','), ',') AS CHAR(300))
  FROM app
  WHERE TRIM(php_extensions) <> ''
  UNION ALL
  SELECT app_id, SUBSTRING_INDEX(rest, ',', 1), SUBSTRING(rest, LOCATE(',', rest) + 1)
  FROM split
  WHERE rest <> ''
),
extensions AS (
  SELECT app_id, JSON_ARRAYAGG(DISTINCT name) AS names
  FROM split
  WHERE name <> ''
  GROUP BY app_id
)
SELECT a.id, COALESCE(e.names, '[]'), a.build_commands, COALESCE(a.persistent_paths, '[]'),
  a.always_on, a.trongate_env, a.mail_capture, a.health_path
FROM app a
LEFT JOIN extensions e ON e.app_id = a.id;

ALTER TABLE app
  DROP COLUMN persistent_paths,
  DROP COLUMN php_extensions,
  DROP COLUMN build_commands,
  DROP COLUMN always_on,
  DROP COLUMN trongate_env,
  DROP COLUMN mail_capture,
  DROP COLUMN health_path;
