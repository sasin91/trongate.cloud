-- The path the rollout gate and the smoke test request (Manifests::health_path):
-- '' is the home page; an API whose home page is a 404 names e.g. /health.
ALTER TABLE app ADD COLUMN health_path VARCHAR(200) NOT NULL DEFAULT '' AFTER mail_capture;
