-- Custom domains go live only after DNS proves ownership.
--
-- project.domain is the domain the customer asked for. verified_domain is set
-- once a TXT record "_trongate-cloud.{domain}" carries the project's token
-- (Platform_actions::verify_project_domain) and is what routes, BASE_URL and the
-- UI use. It is UNIQUE, so two projects can never serve the same hostname, and an
-- unverified claim never blocks the real owner.

ALTER TABLE project
  ADD COLUMN verified_domain VARCHAR(253) NULL AFTER domain,
  ADD UNIQUE KEY unique_verified_domain (verified_domain);
