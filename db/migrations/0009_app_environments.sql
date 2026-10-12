-- Staging apps (tasks/split_staging.php): once a production app's custom
-- domain is live, {slug}.PLATFORM_APPS_DOMAIN moves to a staging copy of it
-- that deploys its own branch, and production answers only on its domain
-- (platform_host 0). The staging app shares its production app's slug, so a
-- slug is unique per environment. Every app so far is a production app.
ALTER TABLE app
  ADD COLUMN environment ENUM('production', 'staging') NOT NULL DEFAULT 'production' AFTER type,
  ADD COLUMN production_app_id INT NULL AFTER environment,
  ADD COLUMN platform_host TINYINT(1) NOT NULL DEFAULT 1 AFTER slug,
  DROP INDEX unique_slug,
  ADD UNIQUE KEY unique_slug_environment (slug, environment),
  ADD KEY idx_production_app (production_app_id),
  ADD CONSTRAINT fk_app_production_app FOREIGN KEY (production_app_id) REFERENCES app (id);
