-- The repository's config/*.php at the deployment's commit (JSON: file name
-- => source). The worker edits them (Config_editor) at every apply and mounts
-- the edited ones over the image's. Deployments built before this cannot be
-- applied again; deploy the app.
ALTER TABLE deployment ADD COLUMN config_files MEDIUMTEXT NULL AFTER image;
