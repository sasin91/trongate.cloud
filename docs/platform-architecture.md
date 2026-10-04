# Platform Architecture (draft)

Trongate.cloud hosts customer Trongate apps on our own k3s cluster. Customers connect GitHub; we build, run and scale their apps. We never access or modify customer servers.

## Decisions

| Area | Choice | Notes |
|---|---|---|
| Cluster | k3s on Hetzner via `hcloud-k3s-platform-kit` (kube-hetzner, Flux, SOPS, Gateway API on Traefik) | Platform components via Flux; tenants created by our worker through the k8s API |
| Builds | Railpack (BuildKit) → FrankenPHP image | Trongate has no root `index.php`/`composer.json`: inject config + `RAILPACK_PHP_ROOT_DIR=public`. Classic mode only — Trongate's per-request `define()`s rule out worker mode. Add the `frame-ancestors` header in the Caddyfile (`.htaccess` is ignored) |
| Tenancy | Namespace per tenant: Deployment, Service, HTTPRoute, config Secret, DB credentials Secret, one `tenant-files` PVC | Quota, LimitRange, default-deny NetworkPolicy, restricted pod security from the kit's template |
| Config | Render `config/config.php` + `config/database.php` into a Secret, mounted over the image's files | Customer apps hardcode config and don't read env |
| Database | Shared `databases` namespace, mariadb-operator; a database + user + grant per tenant | `Database`/`User`/`Grant` CRs live in the tenant namespace (cross-namespace `mariaDbRef` — verify) |
| Cache / sessions | Shared `cache` namespace, Valkey via redis-operator; ACL key prefix per tenant | Sessions here so scale-to-zero doesn't log users out |
| Uploads | JuiceFS on Hetzner object storage; one StorageClass, subdirectory per tenant, mount-pod sharing | Metadata in the shared MariaDB. Persistent paths mounted via `subPath`, seeded by an init container on first deploy |
| Scale-to-zero | Sablier as a Traefik middleware on the HTTPRoute | App pods only; shared namespaces stay up |
| App resources | A small ladder of limit rungs (`Resource_tiers`: small 256Mi/500m, medium 512Mi/1, large 1Gi/2), picked per project; requests derived from the limit (Burstable QoS) | Memory is the binding constraint (2× 8 GB nodes, ~11 GiB allocatable, ~7.4 GiB already requested by the platform before any tenant). Memory requests are 50% of the limit: lower packs more apps but overcommits harder, and when many apps burst at once the node evicts or OOM-kills the pods furthest over their requests. CPU requests are 10% of the limit because CPU over the request only throttles. The tenant quota is shared by every project in the team, so picking a larger rung or adding a project is refused when the team's apps plus one rollout-surge pod of its largest rung would exceed it (`Manifests::quota_shortfall`; the worker rolls out one app at a time). Its `requests.memory` (2Gi, the same point `limits.cpu` 8 stops a team at) is kept well under what the cluster has left for tenants so one team cannot reserve it all; fairness across many teams needs plans or more nodes. The quota itself must admit two top-rung pods, checked when the tenant manifests are built; the LimitRange max is the top rung |
| Custom domains | TXT record proves ownership; routed only once A/AAAA (all of them) point at the load balancer or it is a CNAME into `PLATFORM_APPS_DOMAIN`; then a Gateway per domain in the tenant namespace with one HTTPS listener and an HTTP-01 certificate | See "Custom domains" below |
| Backups | Per-tenant `mariadb-dump` and per-tenant JuiceFS subdirectory via restic; JuiceFS auto metadata backup to the bucket | Kit's assert-non-empty pattern |

## Custom domains

Ported from Scaleweb's CRM (`hostname-gate.ts`, `domain-promotion.ts`), where an
un-pointed customer domain shared a certificate with the app's preview host and
kept its ACME order failing for 23 hours.

- **DNS gate.** `project.verified_domain` (TXT record, db/015) proves ownership,
  not that the domain reaches us. `Manifests::custom_domains` routes it only
  once `project.domain_pointed_at` (db/019) is set: the domain's A/AAAA records
  all point at `PLATFORM_INGRESS_IPV4`/`IPV6`, or it is a CNAME into
  `PLATFORM_APPS_DOMAIN` (`Domain_dns`). The gate reads the stored fact, never
  DNS during a deploy, so a resolver hiccup cannot drop a live domain. The
  `{slug}` host is never gated.
- **BASE_URL waits for the certificate.** `Trongate_config::base_url` uses
  `Manifests::secured_domains`: routed *and* `project.domain_secured_at` set,
  which recheck_domains does once the domain's cert-manager Certificate is
  Ready. A certificate that is pending or never comes (CAA record, rate limit)
  leaves every generated link on the `{slug}` host instead of a host answering
  with Traefik's default certificate.
- **Promotion.** `tasks/recheck_domains.php` checks the domains, records the
  result, and re-applies the app (current deployment's image, no build) when a
  domain points here but the live HTTPRoute does not list it yet, and again
  once its certificate is Ready and the live Deployment's `base-url`
  annotation is not the domain yet. Verifying a domain queues it. Demotion
  needs 3 failed checks in a row and only clears `domain_pointed_at` and
  `domain_secured_at`; the next deploy stops routing the domain, nothing is
  removed from the cluster on the spot (the project still owns the domain).
- **Giving a domain up.** Changing or removing a verified domain frees its
  UNIQUE claim at once, so the old project must not keep serving it until it
  happens to deploy again: `update_project_settings` queues
  `tasks/prune_domains.php`, which deletes the project's Gateways for domains
  it no longer routes. And whichever project routes a domain deletes other
  projects' Gateways for it on every apply (`Platform_task::release_domains`,
  found across namespaces by a `domain-hash` label), in case that task failed
  or has not run. Pruning deletes the certificate Secret too: cert-manager
  removes the Certificate with its Gateway but not the Secret (no
  `--enable-certificate-owner-ref` on this cluster).
- **TLS and routing.** Each routed domain gets `Manifests::domain_gateway`: a
  Gateway in the tenant namespace with one HTTPS listener for exactly that
  hostname, annotated for the `letsencrypt-tenants` ClusterIssuer (infra repo,
  HTTP-01 only). The app's HTTPRoute lists it as a second parent next to the
  platform Gateway. HTTP-01 challenges attach to `acme-http01`, an HTTP-only
  Gateway every tenant namespace gets (`Manifests::acme_gateway`), because the
  platform Gateway's HTTP listener admits routes from `traefik` only.
- **Why not listeners on the platform Gateway:** Flux owns it and would revert
  them, the worker must not be able to edit the listeners serving trongate.cloud
  and `*.trongate.dev`, and a Gateway holds at most 64 listeners. **Why not a
  ListenerSet:** the CRD is installed (Gateway API v1.6.1 standard) but
  Traefik 3.7's GatewayClass does not list it as supported. **Why per domain
  rather than per project:** a listener whose certificate is pending or failing
  stays confined to its own Gateway and Certificate, so one domain can never
  hold up another hostname of the app (CRM's lesson).
- **Safety.** `worker-admission-policy.yaml` checks tenant Gateways like routes:
  only the `traefik` class and the tenant issuer (and no other `cert-manager.io/*`
  annotation, such as `common-name`, which would add names to the certificate),
  one exact non-wildcard hostname per HTTPS listener that is never a platform
  host or under the apps domain, routes and certificate Secrets from the
  Gateway's own namespace, no `allowedListeners` or `spec.tls`, and no worker
  HTTPRoute on `acme-http01`. HTTP-01 alone does not keep platform hosts out of
  tenant certificates (trongate.cloud and every `{slug}.trongate.dev` resolve to
  the same load balancer), so that listener-hostname check is what does. Which tenant may use which custom domain
  is the app's check (`verified_domain` is UNIQUE and proven by TXT); the worker
  writes every tenant, so admission cannot add to it.

## Watch list — keep it simple, act only if it surfaces

- **Noisy neighbours on shared MariaDB.** Tenants and JuiceFS metadata share instances. A heavy tenant could slow other tenants' queries *and* everyone's file I/O. Start with per-user `MAX_USER_CONNECTIONS`; if metadata latency or slow queries show up in metrics, move JuiceFS metadata to its own instance and place tenants by plan.
- **Noisy neighbours on shared Valkey.** `maxmemory`/eviction is instance-wide; one tenant can evict others' sessions. If logouts or cache churn show up, split sessions and cache into separate instances.
- **JuiceFS metadata recovery point.** Chunks written after the last metadata backup are orphaned on restore. If hourly metadata backups prove insufficient, add binlog-based PITR.
- **MariaDB HA mode.** Prefer async replication + MaxScale over Galera for JuiceFS metadata write patterns (unverified).
- **Resource tier fit.** The ladder is seeded from the old fixed limits, not measurements. Once tenants run, compare peak memory per app with its rung (move up on OOMKills) and node memory pressure with the 50% request fraction.
- **Cold starts.** Measure FrankenPHP wake time behind Sablier; tune idle timeouts per plan.

## Spike — measure before building

1. Pod memory for a typical Trongate app on FrankenPHP (sets density / cost per app).
2. Cold-start time through Sablier.
3. Upload + thumbnail latency through JuiceFS; behaviour during a MariaDB failover.
4. gVisor overhead, to decide whether it is default or per-plan.

## Implementation status

The whole pipeline is done in code; nothing has been applied to a cluster yet.

Done in code:

- **Schema**:
  - `db/014_k3s_platform.sql`: BYO-server tables dropped; generic `tasks`/`task_logs`; project `slug`, `persistent_paths`, `php_extensions`, `webhook_secret` (a seed, see webhooks), `always_on`, `database_server_id`, `current_deployment_id`; image-based deployment statuses (`pending → building → deploying → success | failed | rolled_back`) with `commit_sha`, `image`, `trigger_type`; `database_server` with the `shared-1` seed.
  - `db/015_domain_verification.sql`: `project.verified_domain` (UNIQUE).
  - `db/016_resource_tiers.sql`: `project.resource_tier` (default `small`, today's limits).
  - `db/017_deploy_reconcile.sql`: `reconcile` deploy trigger, `project.auto_deploy` and `reconciled_at`, `tasks.team_id` nullable for platform-wide tasks, `scheduled_jobs`.
  - `db/018_project_deletion.sql`: `project.deleted_at` (soft delete).
  - `db/019_custom_domain_dns_gate.sql`: `domain_pointed_at`, `domain_secured_at`, `domain_dns_failures`, `domain_checked_at` (the DNS gate and certificate state).
- **Worker** (`modules/platform/worker`): polls `tasks`, runs `modules/platform/tasks/{script}.php`, logs to `task_logs`, closes the task's deployment. Shell steps get a minimal environment (no `APP_KEY`/`DB_PASSWORD`/tokens) and are killed after 30 minutes (`Runtime::SHELL_TIMEOUT_SECONDS`).
  - Periodic tasks (`Runtime::PERIODIC_TASKS`, script => interval): the poll loop queues each as a team-less task when due. `scheduled_jobs` holds the claim (one conditional UPDATE, so extra replicas never queue a job twice or while its last run is unfinished), when each job last finished and last failed (recorded by the Runtime) and the last run's summary (optional, from the script). Finished and failed runs are pruned after 7 days.
  - Queues deploys through `Platform_actions` (`Worker_actions` over a mysqli `Worker_db`), so it follows the same rules as the UI.
- **Task scripts** (`modules/platform/tasks`):
  - `deploy`: resolves the commit (webhook deploys always build the branch head), downloads the tarball, rejects symlinks that leave the tree, writes `railpack.json` (and an empty `composer.json` so `RAILPACK_PHP_EXTENSIONS` applies), builds with `railpack prepare` + `buildctl` on a BuildKit daemon Pod started for this build only, pushes to `PLATFORM_REGISTRY`, places the database, applies `Manifests::tenant` + `Manifests::app` and waits for the rollout.
  - `rollback`: re-applies a previous image.
  - `destroy_project`: removes a deleted project's app (custom-domain Gateways and their certificate Secrets, HTTPRoute, Middleware, Service, Deployment, MariaDB Grant/User/Database CRs, files PVC, database Secret), then releases its verified domain. Each object is read first and deleted only if it sits in the team's namespace and carries the project's ownership labels (`managed-by=trongate-cloud`, `project-id`, `app.kubernetes.io/name`), pinned to the checked uid. If it fails, nothing retries it automatically: the project stays soft-deleted (hidden everywhere, its verified domain still held) and its app may keep serving until an admin re-queues it with the MCP tool `retry_project_cleanup`. Keeps the namespace, the database (Database CR `cleanupPolicy: Skip`), the files (`reclaimPolicy: Retain`), the images and the soft-deleted row (slug and history).
  - `reconcile_deploys` (periodic, `DEPLOY_RECONCILE_INTERVAL`, default 600s): the backstop for missed GitHub push webhooks, which GitHub does not retry. For projects with Deploy on push on (`project.auto_deploy`) whose webhook has deployed before, compares the branch head with the deployments and queues a `reconcile` deploy for a head nothing has built, after confirming through the GitHub hooks API that the project's webhook is still on the repository (no webhook, or a token that may not read hooks: no deploy). Never re-queues a SHA that has any deployment (a failed head waits for a human), leaves heads under 5 minutes old to their webhook, does not queue while a deploy is pending or running, counts heads undeployed over 15 minutes that nothing will deploy as stuck, and makes one GitHub call per repository branch with the team's token. At most 200 projects per run, longest unchecked first (`project.reconciled_at`), so the cap rotates. The worker runs one task at a time, so a run stops as soon as a customer task is waiting, or when GitHub answers 5xx or cannot be reached; customer deploys wait at most for the GitHub call in flight (normally well under a second, about 90 s at worst with the client's timeout and retries).
  - `recheck_domains`: the custom-domain DNS gate and promotion (see "Custom domains"). Queued per team by the periodic `schedule_domain_rechecks` (`DOMAIN_RECHECK_INTERVAL`, default 600s) and when a domain is verified.
  - `prune_domains`: takes a project's given-up custom domain off the cluster.
- **Library**:
  - Kubernetes client with server-side apply (`modules/services/clients/Kubernetes.php`).
  - Manifest builders (`modules/platform/manifests`): tenant namespaces, apps (with ephemeral-storage limits and Sablier `sablier.enable`/`sablier.group` labels) and per-build buildkitd Pods.
  - Config rendering for the mounted `config.php`/`database.php` (`Trongate_config`).
  - Settings in `config/platform.php`.
- **Platform actions** (`modules/platform/traits/Platform_actions.php`): queue deploys, rollbacks and project deletes, and read projects, deployments and logs by explicit team id. A project has at most one queued deploy; new requests update it instead of adding another. Also handles custom-domain verification (`_trongate-cloud.{domain}` TXT record). The UI, webhooks and MCP all use it.
- **Customer UI**:
  - Onboarding: team → GitHub token → first project. The project gets a slug and webhook secret, and its first deploy is queued.
  - Dashboard: the team's projects with their live state from the cluster (running, asleep, starting, crashing, stuck).
  - Project page: live URLs, the current deployment, the app's live state (polled every 5s), deployment history, Deploy and Rollback (to the previous deployment or one you pick).
  - Project settings: custom domain with DNS verification, persistent paths, extra PHP extensions, always-on, resources (a rung of the tier ladder), and the GitHub webhook URL and secret.
  - Delete project (team owners and admins only; deploys and rollbacks stay open to every member): a confirmation page (type the slug, CSRF-protected) soft-deletes the project and queues `destroy_project`. Refused while a deployment is running; queued deploys are cancelled.
  - Deployment page: logs polled with MX.
  - Team switcher: CSRF-protected.
- **Custom domains**: platform hosts (`BASE_URL`, `PLATFORM_REGISTRY` and their subdomains) are rejected, and a domain is routed only once its TXT record verifies it and its DNS points here; it then gets its own Gateway and HTTP-01 certificate (see "Custom domains"). The admission policy blocks platform hostnames on tenant HTTPRoutes and Gateways.
- **GitHub webhooks** (`POST /webhooks/github/{project_id}`):
  - The `X-Hub-Signature-256` HMAC is checked against a secret derived from the stored seed and `APP_KEY`, so a database leak alone does not reveal it.
  - Answers `ping`.
  - Queues a deploy when a push hits the project's branch, unless the customer turned Deploy on push off in project settings. A replayed push cannot roll an app back, because deploys always build the branch head.
- **Metrics**: `app_tasks` (customer tasks only), `app_deployments`, `app_live_projects`, `app_periodic_task_last_run_timestamp_seconds{task}`, `app_periodic_task_last_failure_timestamp_seconds{task}`, `app_deploy_reconcile_heads{outcome}` (last run; `recovered` > 0 means a push webhook was missed), `app_deploy_reconcile_stuck_heads` (last run; > 0 means a head is not getting deployed, worth an alert).
- **Admin MCP** (`POST /mcp`, `modules/mcp`): one `MCP_SECRET` bearer secret (in the app-only `trongate-cloud-mcp` Secret), tools across all teams, including `audit_app_resources`: a read-only list of labelled per-project objects that no longer match a live project, and of deleted projects whose cleanup failed or is pending (it never repairs), and `retry_project_cleanup` to re-queue such a cleanup. See `docs/mcp.md`.
- **Cluster manifests** (`deploy/k8s`, see its README):
  - Operators; the shared MariaDB, Valkey and JuiceFS.
  - BuildKit: a namespace, quota and NetworkPolicy; daemons are created per build.
  - Sablier.
  - Platform: the app, the worker, and its own MariaDB `platform`, which tenants cannot reach.
  - App access: a cluster-wide read-only role on Deployments and Pods for live app state, and on the other per-project kinds (no Secrets) for the orphan audit (`platform/app-rbac.yaml`).
  - Worker access: RBAC with write-only Secrets (deleted only through a `deletecollection` filtered by the project's labels and the name, so the API server does the label check the worker cannot do by reading), plus admission policies that limit where the worker writes, what the objects contain, and that it only deletes objects carrying its labels (a project's `p{id}-*` objects only with that project's id and name labels). Neither check knows the team: a project's namespace is trusted from the destroy_project payload, which must match the project row.

Still open:

- **Not wired yet:**
  - A failed `destroy_project` is not retried automatically and nobody is alerted; it shows up in the MCP's `audit_app_resources` and is re-queued by hand (`retry_project_cleanup`).
  - Released files PVs and kept `tc_p{id}` databases of deleted projects pile up until removed by hand; there is no purge step yet.
  - No CI workflow builds or pushes the platform images.
  - Valkey session/cache wiring for tenants (per-tenant ACL users).
  - Backups.
  - Image pull credentials for `PLATFORM_REGISTRY` in tenant namespaces.
- **Hardening:**
  - Push credentials are one registry-wide token that buildctl forwards into each build daemon. Scope them per `p{id}` repository with a registry token service.
  - BuildKit mTLS. Today only the NetworkPolicy and the per-build daemon protect it.
  - JuiceFS metadata still shares `shared-1` with tenant databases.
- **Custom domains:**
  - `www.` twins: CRM redirects `www.{domain}` to the apex; here a customer adds the `www` host as its own project domain (one domain per project today).
  - Defence in depth outside the cluster: CAA records on trongate.cloud and trongate.dev with `accounturi` limited to the platform issuers' ACME accounts, so Let's Encrypt refuses those names to the `letsencrypt-tenants` account even if the admission policy were bypassed. A DNS change, not made yet.
  - A renewal that fails later does not move `BASE_URL` back; Let's Encrypt's expiry mails and cert-manager metrics are the signal.
  - A domain pointed through a proxy/CDN (other addresses, no CNAME into the apps domain) never passes the gate.
- **Needs a live cluster:**
  - Several Gateways on Traefik's entrypoints at once, and the `acme-http01` challenge route winning over the platform Gateway's `http-to-https` route.
  - The tenant Gateway CEL validations (`worker-admission-policy.yaml`).
  - Cross-namespace `mariaDbRef`.
  - The platform MariaDB passing restricted Pod Security.
  - The CEL admission policies. `trongate-worker-owned-deletes` and the worker's `deletecollection` on Secrets are not on the cluster yet: apply them, and check them with a server-side dry run (a labelled and an unlabelled `p{id}-*` object, and a Secret via `deletecollection`), before the first tenant is deleted.
  - Railpack's FrankenPHP image running as uid 1000 on port 8080.
  - The empty `composer.json` approach.
  - Per-build buildkitd start-up time.
  - Everything on the Spike list.
