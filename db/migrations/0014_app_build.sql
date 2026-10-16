-- An app's build settings leave the app table: the commands its image build
-- runs (app_build) and its extra PHP extensions, one row each
-- (app_php_extension). Existing values are copied; app.php_extensions and
-- app.build_commands stay until a later migration drops them, so panel pods
-- still on the old image keep working during the rollout.
CREATE TABLE app_build (
  app_id INT PRIMARY KEY,
  commands TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_build_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE
);

CREATE TABLE app_php_extension (
  id INT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  name VARCHAR(40) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_app_php_extension (app_id, name),
  CONSTRAINT fk_app_php_extension_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE
);

INSERT INTO app_build (app_id, commands)
SELECT id, build_commands
FROM app
WHERE build_commands <> '';

-- php_extensions is a list like "imagick,sqlsrv" (older values may hold
-- spaces): split it into one row per name
INSERT IGNORE INTO app_php_extension (app_id, name)
WITH RECURSIVE split (app_id, name, rest) AS (
  SELECT id, CAST('' AS CHAR(255)), CAST(CONCAT(REPLACE(LOWER(TRIM(php_extensions)), ' ', ','), ',') AS CHAR(300))
  FROM app
  WHERE TRIM(php_extensions) <> ''
  UNION ALL
  SELECT app_id, SUBSTRING_INDEX(rest, ',', 1), SUBSTRING(rest, LOCATE(',', rest) + 1)
  FROM split
  WHERE rest <> ''
)
SELECT app_id, name
FROM split
WHERE name <> '';
