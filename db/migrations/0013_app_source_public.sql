-- Apps deployed from a pasted link to a public GitHub repository: builds read
-- it without a token, so the GitHub App need not be installed on it. No App
-- webhook arrives for it, so it deploys only when someone presses Deploy.
ALTER TABLE app
  ADD COLUMN source_public TINYINT(1) NOT NULL DEFAULT 0 AFTER source_provider;
