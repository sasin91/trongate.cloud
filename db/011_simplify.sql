-- Trongate Cloud: Simplify secrets, deployments and logs
-- Migration 011

-- Secrets are now encrypted with APP_KEY; existing rows used per-row keys and must be re-entered
DELETE FROM secrets;

ALTER TABLE secrets
  DROP COLUMN encryption_key,
  DROP INDEX idx_module_id_service,
  ADD UNIQUE KEY unique_module_id_service (module, module_id, service);

-- Provider connections are tracked via secrets (module='team', service=provider)
ALTER TABLE team DROP COLUMN services;

-- Deployment output lives in server_logs (via server_tasks.deployment_id)
ALTER TABLE deployment
  DROP COLUMN script,
  DROP COLUMN output,
  DROP COLUMN exitcode,
  DROP COLUMN metadata;

-- Unused; SSH access is handled by the platform CA
DROP TABLE IF EXISTS server_keypair;

-- Link log lines to the task that produced them
ALTER TABLE server_logs
  ADD COLUMN task_id INT NULL AFTER server_id,
  ADD CONSTRAINT fk_server_logs_task FOREIGN KEY (task_id) REFERENCES server_tasks(id),
  ADD INDEX idx_task_id (task_id, id);
