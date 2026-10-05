# Platform Architecture (draft)

Trongate.cloud hosts customer Trongate apps on our own k3s cluster. Customers sign in with GitHub and install our GitHub App on their repositories; we build, run and scale their apps. We never access or modify customer servers.

## Decisions

| Area | Choice | Notes |
|---|---|---|
| Cluster | k3s on Hetzner via `hcloud-k3s-platform-kit` (kube-hetzner, Flux, SOPS, Gateway API on Traefik) | Platform components via Flux; tenants created by our worker through the k8s API |
| Builds | Platform Dockerfile on a prebuilt FrankenPHP base (`docker/app-base`), built by BuildKit | Every Trongate app has the same shape (`public/`, `config/`, optional `composer.json`), so the platform writes the Dockerfile; builds are copy + composer. Classic mode only — Trongate's per-request `define()`s rule out worker mode. Add the `frame-ancestors` header in the Caddyfile (`.htaccess` is ignored) |
| Tenancy | Namespace per project (`project-{id}`, stored in `project.namespace`), holding its apps; per app a Deployment, Service, HTTPRoute, config Secret, DB credentials Secret, one `tenant-files` PVC (all `p{app id}-*`) | Quota, LimitRange, default-deny NetworkPolicy, restricted pod security from the kit's template. `allow-same-project` lets the apps of a project call each other (`http://p{id}-app`, TCP 8080 on the pod) in both directions; other projects cannot reach them |
| Config | Render `config/config.php` + `config/database.php` into a Secret, mounted over the image's files | Customer apps hardcode config and don't read env |
| Database | Shared `databases` namespace, mariadb-operator; a database + user + grant per app (`tc_p{app id}`) | `Database`/`User`/`Grant` CRs live in the app's project namespace (cross-namespace `mariaDbRef` — verify) |
| Sessions | Shared `cache` namespace, one Valkey StatefulSet; an ACL user per app (`tc_p{app id}`, keys `tc_p{app id}:*`) created by the worker on every apply | A php.ini in `conf.d` sets the phpredis session handler (`persistent=1`), so redeploys and scale-to-zero don't log visitors out and customers change no code. Every build adds the `redis` extension; an image without it (a rollback) keeps file sessions |
| Uploads | JuiceFS on Hetzner object storage; one StorageClass, subdirectory per tenant, mount-pod sharing | Metadata in the shared MariaDB. Persistent paths mounted via `subPath`, seeded by an init container on first deploy |
| Scale-to-zero | Sablier as a Traefik middleware on the HTTPRoute | App pods only; shared namespaces stay up. Only a request through Traefik wakes a sleeping app: a direct in-cluster call from another app of the project does not, so an app that others call needs `always_on` |
| App resources | No size to pick and no CPU or memory limit yet; every app requests 128Mi / 50m (`Manifests::APP_RESOURCES`) so the scheduler can place it, and grows past that as it needs | We observe what real apps use before enforcing limits or pricing sizes. Memory is the binding constraint (2× 8 GB nodes, ~11 GiB allocatable, ~7.4 GiB already requested by the platform before any tenant), so the small request packs apps densely and overcommits: when many apps burst at once the node evicts the pods furthest over their requests. The tenant quota, shared by every app in the project, bounds only disk, pod count and exposure; ephemeral-storage keeps a 1Gi limit per container so one app cannot fill a node's disk |
| Custom domains | TXT record proves ownership; routed only once A/AAAA (all of them) point at the load balancer or it is a CNAME into `PLATFORM_APPS_DOMAIN`; then a Gateway per domain in the tenant namespace with one HTTPS listener and an HTTP-01 certificate | See "Custom domains" below |
| Backups | Per-tenant `mariadb-dump` and per-tenant JuiceFS subdirectory via restic; JuiceFS auto metadata backup to the bucket | Kit's assert-non-empty pattern |

## Custom domains

Ported from Scaleweb's CRM (`hostname-gate.ts`, `domain-promotion.ts`), where an
un-pointed customer domain shared a certificate with the app's preview host and
kept its ACME order failing for 23 hours.

- **DNS gate.** `app.verified_domain` (TXT record) proves ownership,
  not that the domain reaches us. `Manifests::custom_domains` routes it only
  once `app.domain_pointed_at` is set: the domain's A/AAAA records
  all point at `PLATFORM_INGRESS_IPV4`/`IPV6`, or it is a CNAME into
  `PLATFORM_APPS_DOMAIN` (`Domain_dns`). The gate reads the stored fact, never
  DNS during a deploy, so a resolver hiccup cannot drop a live domain. The
  `{slug}` host is never gated.
- **BASE_URL waits for the certificate.** `Trongate_config::base_url` uses
  `Manifests::secured_domains`: routed *and* `app.domain_secured_at` set,
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
  removed from the cluster on the spot (the app still owns the domain).
- **Giving a domain up.** Changing or removing a verified domain frees its
  UNIQUE claim at once, so the old app must not keep serving it until it
  happens to deploy again: `update_app_settings` queues
  `tasks/prune_domains.php`, which deletes the app's Gateways for domains
  it no longer routes. And whichever app routes a domain deletes other
  apps' Gateways for it on every apply (`Platform_task::release_domains`,
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
  rather than per app:** a listener whose certificate is pending or failing
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
- **What apps actually use.** Apps run without CPU or memory limits. Once tenants run, compare peak memory and CPU per app with the 128Mi / 50m request and watch node memory pressure and evictions; that decides limits, requests and later pricing.
- **Cold starts.** Measure FrankenPHP wake time behind Sablier; tune idle timeouts per plan.

## Spike — measure before building

1. Pod memory for a typical Trongate app on FrankenPHP (sets density / cost per app).
2. Cold-start time through Sablier.
3. Upload + thumbnail latency through JuiceFS; behaviour during a MariaDB failover.
4. gVisor overhead, to decide whether it is default or per-plan.

## Implementation status

The whole pipeline is done in code; nothing has been applied to a cluster yet.

Done in code:

- **Schema** (`db/schema.sql`, one file applied to an empty database; numbered migrations start once there are customers to migrate): `customer` (password and/or linked GitHub account `github_user_id`); `project` (name, owner, `namespace` NOT NULL UNIQUE `project-{id}`, soft delete) and `project_member` (`owner` | `member`); `secrets` (APP_KEY-encrypted; GitHub user access tokens under module `customer`); `app` (slug, source repository and branch, custom-domain state, runtime settings, soft delete); `deployment` (image-based statuses `pending → building → deploying → success | failed | rolled_back`, `commit_sha`, `image`, `trigger_type`, `customer_id` who pressed Deploy); `database_server` with the `shared-1` seed; worker `tasks`/`task_logs`/`scheduled_jobs`.
- **Worker** (`modules/platform/worker`): polls `tasks`, runs `modules/platform/tasks/{script}.php`, logs to `task_logs`, closes the task's deployment. Shell steps get a minimal environment (no `APP_KEY`/`DB_PASSWORD`/tokens) and are killed after 30 minutes (`Runtime::SHELL_TIMEOUT_SECONDS`).
  - Periodic tasks (`Runtime::PERIODIC_TASKS`, script => interval): the poll loop queues each as a project-less task when due. `scheduled_jobs` holds the claim (one conditional UPDATE, so extra replicas never queue a job twice or while its last run is unfinished), when each job last finished and last failed (recorded by the Runtime) and the last run's summary (optional, from the script). Finished and failed runs are pruned after 7 days.
  - Queues deploys through `Platform_actions` (`Worker_actions` with the framework's `Db` class over the worker's PDO connection), so it follows the same rules as the UI.
- **Task scripts** (`modules/platform/tasks`):
  - `deploy`: resolves the commit (webhook deploys always build the branch head), downloads the tarball, rejects symlinks that leave the tree, writes a Dockerfile `FROM PLATFORM_APP_BASE_IMAGE` (extra extensions from the app's settings, composer install, the app's files, its own Caddyfile if it ships one), builds it with `buildctl` on a BuildKit daemon Pod started for this build only, pushes to `PLATFORM_REGISTRY`, places the database, applies `Manifests::tenant` + `Manifests::app` and waits for the rollout.
  - `rollback`: re-applies a previous image.
  - `destroy_app`: removes a deleted app (custom-domain Gateways and their certificate Secrets, HTTPRoute, Middleware, Service, Deployment, MariaDB Grant/User/Database CRs, files PVC, database Secret), then releases its verified domain. Each object is read first and deleted only if it sits in the project's stored namespace and carries the app's ownership labels (`managed-by=trongate-cloud`, `project-id` (the app id; the key predates the rename), `app.kubernetes.io/name`), pinned to the checked uid. If it fails, nothing retries it automatically: the app stays soft-deleted (hidden everywhere, its verified domain still held) and may keep serving until an admin re-queues it with the MCP tool `retry_app_cleanup`. Keeps the namespace (other apps of the project live there), the database (Database CR `cleanupPolicy: Skip`), the files (`reclaimPolicy: Retain`), the images and the soft-deleted row (slug and history).
  - `destroy_project`: deletes the namespace of a deleted, empty project. Only when the project row is soft-deleted, it has no live app, every deleted app's `destroy_app` finished, the Namespace carries the tenant labels `Manifests::tenant()` gives it, and the cluster shows nothing of an app left in it (only the tenant scaffolding and the ACME Gateway); the delete is pinned to the checked uid. Otherwise it fails with the reason logged, and an admin re-queues it with `retry_project_cleanup`. Keeps the soft-deleted project row with its members and deployments.
  - `reconcile_deploys` (periodic, `DEPLOY_RECONCILE_INTERVAL`, default 600s): the backstop for missed GitHub push webhooks, which GitHub does not retry. For apps with Deploy on push on (`app.auto_deploy`) whose webhook has deployed before, compares the branch head with the deployments and queues a `reconcile` deploy for a head nothing has built, as long as the GitHub App is still installed on the repository (removed: no deploy). Never re-queues a SHA that has any deployment (a failed head waits for a human), leaves heads under 5 minutes old to their webhook, does not queue while a deploy is pending or running, counts heads undeployed over 15 minutes that nothing will deploy as stuck, and makes one GitHub call per repository branch with an installation token of the GitHub App (two more per repository to mint it). At most 200 apps per run, longest unchecked first (`app.reconciled_at`), so the cap rotates. The worker runs one task at a time, so a run stops as soon as a customer task is waiting, or when GitHub answers 5xx or cannot be reached; customer deploys wait at most for the GitHub call in flight (normally well under a second, about 90 s at worst with the client's timeout and retries).
  - `recheck_domains`: the custom-domain DNS gate and promotion (see "Custom domains"). Queued per project by the periodic `schedule_domain_rechecks` (`DOMAIN_RECHECK_INTERVAL`, default 600s) and when a domain is verified.
  - `prune_domains`: takes an app's given-up custom domain off the cluster.
- **Library**:
  - Kubernetes client with server-side apply (`modules/services/clients/Kubernetes.php`).
  - Manifest builders (`modules/platform/manifests`): tenant namespaces, apps (with ephemeral-storage limits and Sablier `sablier.enable`/`sablier.group` labels) and per-build buildkitd Pods.
  - Config rendering for the mounted `config.php`/`database.php` (`Trongate_config`).
  - Settings in `config/platform.php`.
- **Platform actions** (`modules/platform/traits/Platform_actions.php`): queue deploys, rollbacks and app deletes, and read apps, deployments and logs by explicit project id (each call checks the app belongs to that project). An app has at most one queued deploy; new requests update it instead of adding another. Also projects and members: create (with its namespace and the owner as member), membership lookups, add/remove members by email, rename, and delete an empty project (`queue_project_delete`, which queues `destroy_project`). Also handles custom-domain verification (`_trongate-cloud.{domain}` TXT record). The UI, webhooks and MCP all use it.
- **Customer UI**:
  - Sign-in: email and password, or Continue with GitHub (`modules/github_auth`, the GitHub App's OAuth client). A GitHub account signs in the customer it is linked to, or signs up with its verified primary email; an email that already has a password account must sign in with the password and connect GitHub from there. See `docs/github-app.md`.
  - Onboarding: connect GitHub (sign in through the App, then install it on the repositories to deploy) → first app. The first project is created with it and named after the app (renamable later). The app gets a slug and its first deploy is queued.
  - Dashboard: the customer's projects, each with its apps and their live state from the cluster (running, asleep, starting, crashing, stuck). No project switcher: every page is authorized against the project or app it names.
  - Project page (`projects/{id}`): its apps and members. New project, new app in a project (`projects/{id}/apps/new`), and for the owner: rename, add a member by the email of an existing account, remove a member (never the owner), and delete the project once it has no apps (queues `destroy_project`).
  - App page (`apps/{id}`): live URLs, the current deployment, the app's live state (polled every 5s), deployment history, Deploy and Rollback (to the previous deployment or one you pick).
  - App settings: custom domain with DNS verification, persistent paths, extra PHP extensions, always-on and Deploy on push.
  - Delete app (project owner only; deploys, rollbacks and settings stay open to every member): a confirmation page (type the slug, CSRF-protected) soft-deletes the app and queues `destroy_app`. Refused while a deployment is running; queued deploys are cancelled.
  - Deployment page: logs polled with MX.
- **Custom domains**: platform hosts (`BASE_URL`, `PLATFORM_REGISTRY` and their subdomains) are rejected, and a domain is routed only once its TXT record verifies it and its DNS points here; it then gets its own Gateway and HTTP-01 certificate (see "Custom domains"). The admission policy blocks platform hostnames on tenant HTTPRoutes and Gateways.
- **GitHub App webhook** (`POST /webhooks/github`, one for every installation):
  - The `X-Hub-Signature-256` HMAC is checked against `GITHUB_APP_WEBHOOK_SECRET`.
  - Answers `ping`; other events than `push` are acknowledged and ignored.
  - A push queues a deploy of every app built from that repository and branch, unless its customer turned Deploy on push off in app settings. A replayed push cannot roll an app back, because deploys always build the branch head.
- **Builds read GitHub with installation tokens**: `deploy` and `reconcile_deploys` mint a token scoped to the one repository with read-only contents (`Github_app::repository_token`), so a build never depends on who added the app or whether they are still signed in.
- **Metrics**: `app_tasks` (customer tasks only), `app_deployments`, `app_live_apps`, `app_periodic_task_last_run_timestamp_seconds{task}`, `app_periodic_task_last_failure_timestamp_seconds{task}`, `app_deploy_reconcile_heads{outcome}` (last run; `recovered` > 0 means a push webhook was missed), `app_deploy_reconcile_stuck_heads` (last run; > 0 means a head is not getting deployed, worth an alert).
- **Admin MCP** (`POST /mcp`, `modules/mcp`): one `MCP_SECRET` bearer secret (in the app-only `trongate-cloud-mcp` Secret), tools across all projects, including `audit_app_resources`: a read-only list of labelled per-app objects that no longer match a live app, and of deleted apps and projects whose cleanup failed or is pending (it never repairs), and `retry_app_cleanup` / `retry_project_cleanup` to re-queue such a cleanup. See `docs/mcp.md`.
- **Cluster manifests** (`deploy/k8s`, see its README):
  - Operators; the shared MariaDB, Valkey and JuiceFS.
  - BuildKit: a namespace, quota and NetworkPolicy; daemons are created per build.
  - Sablier.
  - Platform: the app, the worker, and its own MariaDB `platform`, which tenants cannot reach.
  - App access: a cluster-wide read-only role on Deployments and Pods for live app state, and on the other per-app kinds (no Secrets) for the orphan audit (`platform/app-rbac.yaml`).
  - Worker access: RBAC with write-only Secrets (deleted only through a `deletecollection` filtered by the app's labels and the name, so the API server does the label check the worker cannot do by reading), plus admission policies that limit where the worker writes (tenant namespaces `project-*`), what the objects contain, and that it only deletes objects carrying its labels (an app's `p{id}-*` objects only with that app's id and name labels; a tenant Namespace only with the tenant labels). Neither check knows which project an app belongs to: `destroy_app` takes the namespace from the project row named in its payload, and an app row that still exists must belong to that project.

Still open:

- **Not wired yet:**
  - A failed `destroy_app` or `destroy_project` is not retried automatically and nobody is alerted; it shows up in the MCP's `audit_app_resources` and is re-queued by hand (`retry_app_cleanup`, `retry_project_cleanup`).
  - Released files PVs and kept `tc_p{id}` databases of deleted apps pile up until removed by hand; there is no purge step yet.
  - No CI workflow builds or pushes the platform images.
  - Backups.
  - Image pull credentials for `PLATFORM_REGISTRY` in tenant namespaces.
- **Hardening:**
  - Push credentials are one registry-wide token that buildctl forwards into each build daemon. Scope them per `p{id}` repository with a registry token service.
  - BuildKit mTLS. Today only the NetworkPolicy and the per-build daemon protect it.
  - JuiceFS metadata still shares `shared-1` with tenant databases.
- **Custom domains:**
  - `www.` twins: CRM redirects `www.{domain}` to the apex; here a customer adds the `www` host as its own app domain (one domain per app today).
  - Defence in depth outside the cluster: CAA records on trongate.cloud and trongate.dev with `accounturi` limited to the platform issuers' ACME accounts, so Let's Encrypt refuses those names to the `letsencrypt-tenants` account even if the admission policy were bypassed. A DNS change, not made yet.
  - A renewal that fails later does not move `BASE_URL` back; Let's Encrypt's expiry mails and cert-manager metrics are the signal.
  - A domain pointed through a proxy/CDN (other addresses, no CNAME into the apps domain) never passes the gate.
- **Needs a live cluster:**
  - Several Gateways on Traefik's entrypoints at once, and the `acme-http01` challenge route winning over the platform Gateway's `http-to-https` route.
  - The tenant Gateway CEL validations (`worker-admission-policy.yaml`).
  - Cross-namespace `mariaDbRef`.
  - The platform MariaDB passing restricted Pod Security.
  - The CEL admission policies. `trongate-worker-owned-deletes` and the worker's `deletecollection` on Secrets are not on the cluster yet: apply them, and check them with a server-side dry run (a labelled and an unlabelled `p{id}-*` object, and a Secret via `deletecollection`), before the first tenant is deleted.
  - The app base FrankenPHP image (`docker/app-base`) running as uid 1000 on port 8080.
  - The empty `composer.json` approach.
  - Per-build buildkitd start-up time.
  - Everything on the Spike list.
