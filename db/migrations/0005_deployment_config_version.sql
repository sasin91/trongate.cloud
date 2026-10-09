-- Which version of the platform's app config (Trongate_config::CONFIG_VERSION)
-- the deployment was last applied with; NULL for deployments applied before
-- this was recorded. redeploy_outdated_apps redeploys apps whose current
-- deployment is behind, on the same commit (trigger 'platform').
ALTER TABLE deployment ADD COLUMN config_version INT NULL AFTER failure;
ALTER TABLE deployment MODIFY trigger_type ENUM('manual', 'webhook', 'mcp', 'rollback', 'reconcile', 'platform') NOT NULL DEFAULT 'manual';
