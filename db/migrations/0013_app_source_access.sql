-- How builds read an app's repository: through the trongate.cloud GitHub App
-- (an installation token), or as a pasted link to a public repository, read
-- without a token. No App webhook arrives for a public link, so such an app
-- deploys only when someone presses Deploy.
ALTER TABLE app
  ADD COLUMN source_access ENUM('github_app', 'public_link') NOT NULL DEFAULT 'github_app' AFTER source_provider;
