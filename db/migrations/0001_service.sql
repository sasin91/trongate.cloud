-- Project services (Project_service): an image that runs next to a project's apps
CREATE TABLE IF NOT EXISTS service (
  id INT PRIMARY KEY AUTO_INCREMENT,
  project_id INT NOT NULL,
  -- Lowercase slug; the apps get {NAME}_URL
  name VARCHAR(40) NOT NULL,
  image VARCHAR(500) NOT NULL,
  port SMALLINT UNSIGNED NOT NULL,
  -- The container's memory request and limit
  memory_mb INT UNSIGNED NOT NULL,
  deployed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  -- NULL once deleted, so a live name is unique and a deleted one can be reused
  live_name VARCHAR(40) AS (IF(deleted_at IS NULL, name, NULL)) VIRTUAL,
  UNIQUE KEY unique_project_live_name (project_id, live_name),
  CONSTRAINT fk_service_project FOREIGN KEY (project_id) REFERENCES project (id)
);
