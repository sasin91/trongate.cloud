-- Trongate Cloud: Host customer apps on our own k3s cluster
-- Migration 014
-- Customers no longer bring servers: drop the BYO-server tables, add a generic
-- task queue + logs, per-project platform settings and shared database servers.

-- ---------------------------------------------------------------------------
-- 1. Drop BYO-server tables
-- ---------------------------------------------------------------------------

DROP TABLE IF EXISTS server_logs;
DROP TABLE IF EXISTS server_tasks;

-- The deployment -> server FK was created unnamed, so look its name up
SET @fk_name := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deployment'
    AND COLUMN_NAME = 'server_id'
    AND REFERENCED_TABLE_NAME = 'server'
  LIMIT 1
);
SET @drop_fk := IF(
  @fk_name IS NULL,
  'SELECT 1',
  CONCAT('ALTER TABLE deployment DROP FOREIGN KEY `', @fk_name, '`')
);
PREPARE drop_fk_stmt FROM @drop_fk;
EXECUTE drop_fk_stmt;
DEALLOCATE PREPARE drop_fk_stmt;

ALTER TABLE deployment
  DROP INDEX IF EXISTS idx_server_created,
  DROP COLUMN IF EXISTS server_id;

DROP TABLE IF EXISTS server;

-- Server env vars were stored as secrets
DELETE FROM secrets WHERE module = 'server';

-- ---------------------------------------------------------------------------
-- 2. Shared database servers (mariadb-operator MariaDB CRs)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS database_server (
  id INT PRIMARY KEY AUTO_INCREMENT,
  label VARCHAR(255) NOT NULL,
  host VARCHAR(255) NOT NULL,
  port INT NOT NULL DEFAULT 3306,
  operator_name VARCHAR(63) NOT NULL COMMENT 'MariaDB CR name',
  operator_namespace VARCHAR(63) NOT NULL DEFAULT 'databases',
  capacity INT NOT NULL DEFAULT 200 COMMENT 'Max tenant databases placed here',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO database_server (label, host, operator_name)
SELECT 'shared-1', 'shared-1.databases.svc.cluster.local', 'shared-1'
WHERE NOT EXISTS (SELECT 1 FROM database_server WHERE operator_name = 'shared-1');

-- ---------------------------------------------------------------------------
-- 3. Deployment: image-based lifecycle, triggered by members, webhooks or MCP
-- ---------------------------------------------------------------------------

-- Widen the enum first so existing 'running' rows can be mapped
ALTER TABLE deployment
  MODIFY status ENUM('pending', 'running', 'building', 'deploying', 'success', 'failed', 'rolled_back') DEFAULT 'pending';

UPDATE deployment
SET status = 'failed', completed_at = COALESCE(completed_at, NOW())
WHERE status = 'running';

-- Webhook and token-triggered deployments have no team member / customer
ALTER TABLE deployment
  MODIFY status ENUM('pending', 'building', 'deploying', 'success', 'failed', 'rolled_back') DEFAULT 'pending',
  MODIFY team_member_id INT NULL,
  MODIFY customer_id INT NULL,
  ADD COLUMN commit_sha VARCHAR(40) NULL AFTER source_url,
  ADD COLUMN image VARCHAR(255) NULL AFTER commit_sha,
  ADD COLUMN trigger_type ENUM('manual', 'webhook', 'mcp', 'rollback') NOT NULL DEFAULT 'manual' AFTER image;

-- ---------------------------------------------------------------------------
-- 4. Project: platform settings
-- ---------------------------------------------------------------------------

ALTER TABLE project
  ADD COLUMN slug VARCHAR(40) NULL AFTER label,
  ADD COLUMN persistent_paths JSON DEFAULT '[]',
  ADD COLUMN php_extensions VARCHAR(255) NOT NULL DEFAULT '',
  ADD COLUMN webhook_secret CHAR(64) NULL,
  ADD COLUMN always_on TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN database_server_id INT NULL,
  ADD COLUMN current_deployment_id INT NULL;

-- DNS-label safe slug for existing projects: "{label}-{id}"
UPDATE project
SET slug = TRIM(BOTH '-' FROM CONCAT(
  TRIM(BOTH '-' FROM LEFT(LOWER(REGEXP_REPLACE(label, '[^A-Za-z0-9]+', '-')), 30)),
  '-',
  id
))
WHERE slug IS NULL;

ALTER TABLE project
  MODIFY slug VARCHAR(40) NOT NULL,
  ADD UNIQUE KEY unique_slug (slug),
  ADD CONSTRAINT fk_project_database_server FOREIGN KEY (database_server_id) REFERENCES database_server(id),
  ADD CONSTRAINT fk_project_current_deployment FOREIGN KEY (current_deployment_id) REFERENCES deployment(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 5. Generic task queue (worker: modules/platform/worker)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tasks (
  id INT PRIMARY KEY AUTO_INCREMENT,
  team_id INT NOT NULL,
  project_id INT NULL,
  deployment_id INT NULL,
  script VARCHAR(100) NOT NULL COMMENT 'modules/platform/tasks/{script}.php',
  payload JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  started_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  failed_at TIMESTAMP NULL,
  error_message TEXT NULL,
  INDEX idx_pending (started_at, completed_at, failed_at),
  CONSTRAINT fk_tasks_team FOREIGN KEY (team_id) REFERENCES team(id),
  CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES project(id),
  CONSTRAINT fk_tasks_deployment FOREIGN KEY (deployment_id) REFERENCES deployment(id)
);

CREATE TABLE IF NOT EXISTS task_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  task_id INT NOT NULL,
  level ENUM('info', 'warn', 'error') DEFAULT 'info',
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_task_id (task_id, id),
  CONSTRAINT fk_task_logs_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
