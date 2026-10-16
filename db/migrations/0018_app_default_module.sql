-- The module an app opens on (Trongate's DEFAULT_MODULE), set by the
-- platform in the app's config; '' keeps the app's own. A fresh
-- trongate-framework app opens on 'setup', which cannot save its changes
-- on the platform, so the new-app wizard suggests 'welcome'.
ALTER TABLE app_runtime
  ADD COLUMN default_module VARCHAR(50) NOT NULL DEFAULT '' AFTER trongate_env;
