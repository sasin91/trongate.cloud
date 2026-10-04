-- A verified custom domain is routed only once its DNS reaches the cluster,
-- and becomes BASE_URL only once its certificate is issued.
--
-- The TXT record (db/015) proves ownership, not that the domain points here.
-- Routing a domain that still resolves elsewhere gets nothing but a failing
-- HTTP-01 challenge, and BASE_URL would send visitors to a host that does not
-- serve the app. So the worker's recheck_domains task records when it first saw
-- verified_domain's A/AAAA (or CNAME) point at the platform, and only then do
-- Manifests route it. BASE_URL (Trongate_config::base_url) waits one step
-- longer, for the domain's Certificate to be Ready: a certificate that is
-- pending or never comes (CAA record, rate limit) must not move the app's links
-- to a host answering with Traefik's default certificate.
--
-- domain_pointed_at   NULL: not seen pointing here (yet), including never
--                     checked. Set on the first passing check, which also
--                     re-applies the app (promotion).
-- domain_secured_at   NULL: its certificate is not known to be issued. Set by
--                     recheck_domains once the Certificate is Ready, which
--                     re-applies the app with the domain as BASE_URL. Cleared
--                     with domain_pointed_at.
-- domain_dns_failures Consecutive failed checks. Only after
--                     recheck_domains' DEMOTE_AFTER_FAILURES in a row is
--                     domain_pointed_at cleared, so a resolver hiccup never
--                     drops a live domain. Demotion changes nothing in the
--                     cluster; the next deploy stops routing the domain.
-- domain_checked_at   When recheck_domains last looked (shown in settings).
--
-- Changing the domain clears all four together with verified_domain
-- (Project_records::update_project_settings), and queues prune_domains to take
-- the old domain's Gateway off the cluster.

ALTER TABLE project
  ADD COLUMN domain_pointed_at TIMESTAMP NULL AFTER verified_domain,
  ADD COLUMN domain_secured_at TIMESTAMP NULL AFTER domain_pointed_at,
  ADD COLUMN domain_dns_failures TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER domain_secured_at,
  ADD COLUMN domain_checked_at TIMESTAMP NULL AFTER domain_dns_failures;
