-- Per-project resource tier.
--
-- The app container's CPU/memory limits come from a rung on the ladder in
-- modules/platform/manifests/Resource_tiers.php; requests are derived from the
-- limits there. 'small' is the limits every app had before tiers (256Mi, 500m).
-- Only the name is stored, so the ladder stays the single source of truth; an
-- unknown name deploys as the default rung.

ALTER TABLE project
  ADD COLUMN resource_tier VARCHAR(20) NOT NULL DEFAULT 'small' AFTER always_on;
