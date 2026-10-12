-- Staging environments (Platform_actions::create_staging_app): a copy of a
-- production app that deploys from its own branch, on {slug}-staging. Every
-- app so far is a production app.
ALTER TABLE app
  ADD COLUMN environment ENUM('production', 'staging') NOT NULL DEFAULT 'production' AFTER type,
  ADD COLUMN production_app_id INT NULL AFTER environment,
  ADD KEY idx_production_app (production_app_id),
  ADD CONSTRAINT fk_app_production_app FOREIGN KEY (production_app_id) REFERENCES app (id);
