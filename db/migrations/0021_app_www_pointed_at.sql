-- www.{verified_domain} seen pointing at the cluster too (recheck_domains):
-- it gets its own certificate and redirects to the domain (Manifests::www_alias)
ALTER TABLE app
  ADD COLUMN www_pointed_at TIMESTAMP NULL DEFAULT NULL AFTER domain_checked_at;
