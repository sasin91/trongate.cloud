-- An app's processes (Manifests::_process_deployment): other entrypoints run
-- from the app's own image, settings and database, e.g. a queue worker
-- `php bin/queue.php work --time-limit=3600`. Each runs as a p{app id}-{name}
-- Deployment that never sleeps, with this many replicas.
CREATE TABLE IF NOT EXISTS app_process (
  id INT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  -- Lowercase slug, not 'app' (p{id}-app is the app itself)
  name VARCHAR(20) NOT NULL,
  command VARCHAR(500) NOT NULL,
  replicas TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_app_process_name (app_id, name),
  CONSTRAINT fk_app_process_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE
);
