-- An app's worker command (Manifests::_worker_deployment), e.g.
-- `php bin/messenger.php consume --time-limit=3600`: when set, each deploy
-- also runs it in a p{id}-worker Deployment that never sleeps. '' = none.
ALTER TABLE app ADD COLUMN worker_command VARCHAR(500) NOT NULL DEFAULT '' AFTER php_extensions;
