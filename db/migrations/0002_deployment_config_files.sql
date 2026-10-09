-- The repository's config/*.php at the deployment's commit (JSON: file name
-- => source). The worker edits them (Config_editor) at every apply and mounts
-- the edited ones over the image's. Deployments built before this cannot be
-- applied again; deploy the app.
ALTER TABLE deployment ADD COLUMN config_files MEDIUMTEXT NULL AFTER image;

-- Why a deployment failed and how to recover (JSON, Config_error::to_array),
-- shown on the deployment page and by the MCP tools.
ALTER TABLE deployment ADD COLUMN failure TEXT NULL AFTER config_files;
