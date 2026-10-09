-- The repository's config/config.php at the deployment's commit. The worker
-- edits it (Config_editor) at every apply and mounts it over the image's.
-- Older deployments read it from GitHub on their next apply.
ALTER TABLE deployment ADD COLUMN config_php MEDIUMTEXT NULL AFTER image;
