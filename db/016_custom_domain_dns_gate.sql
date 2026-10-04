-- A verified custom domain is routed only once its DNS reaches the cluster.
--
-- The TXT record (db/015) proves ownership, not that the domain points here.
-- Routing a domain that still resolves elsewhere gets nothing but a failing
-- HTTP-01 challenge, and BASE_URL would send visitors to a host that does not
-- serve the app. So the worker's recheck_domains task records when it first saw
-- verified_domain's A/AAAA (or CNAME) point at the platform, and only then do
-- Manifests route it and Trongate_config make it BASE_URL.
--
-- domain_pointed_at   NULL: not seen pointing here (yet), including never
--                     checked. Set on the first passing check, which also
--                     re-applies the app (promotion).
-- domain_dns_failures Consecutive failed checks. Only after
--                     recheck_domains' DEMOTE_AFTER_FAILURES in a row is
--                     domain_pointed_at cleared, so a resolver hiccup never
--                     drops a live domain. Demotion changes nothing in the
--                     cluster; the next deploy stops routing the domain.
-- domain_checked_at   When recheck_domains last looked (shown in settings).
--
-- Changing the domain clears all three together with verified_domain
-- (Project_records::update_project_settings).

ALTER TABLE project
  ADD COLUMN domain_pointed_at TIMESTAMP NULL AFTER verified_domain,
  ADD COLUMN domain_dns_failures TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER domain_pointed_at,
  ADD COLUMN domain_checked_at TIMESTAMP NULL AFTER domain_dns_failures;
