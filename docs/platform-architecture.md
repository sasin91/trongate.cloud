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
| Backups | Per-tenant `mariadb-dump` and per-tenant JuiceFS subdirectory via restic; JuiceFS auto metadata backup to the bucket | Kit's assert-non-empty pattern |

## Watch list — keep it simple, act only if it surfaces

- **Noisy neighbours on shared MariaDB.** Tenants and JuiceFS metadata share instances. A heavy tenant could slow other tenants' queries *and* everyone's file I/O. Start with per-user `MAX_USER_CONNECTIONS`; if metadata latency or slow queries show up in metrics, move JuiceFS metadata to its own instance and place tenants by plan.
- **Noisy neighbours on shared Valkey.** `maxmemory`/eviction is instance-wide; one tenant can evict others' sessions. If logouts or cache churn show up, split sessions and cache into separate instances.
- **JuiceFS metadata recovery point.** Chunks written after the last metadata backup are orphaned on restore. If hourly metadata backups prove insufficient, add binlog-based PITR.
- **MariaDB HA mode.** Prefer async replication + MaxScale over Galera for JuiceFS metadata write patterns (unverified).
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
  - `db/016_project_deletion.sql`: `project.deleted_at` (soft delete).
- **Worker** (`modules/platform/worker`): polls `tasks`, runs `modules/platform/tasks/{script}.php`, logs to `task_logs`, closes the task's deployment. Shell steps get a minimal environment (no `APP_KEY`/`DB_PASSWORD`/tokens) and are killed after 30 minutes (`Runtime::SHELL_TIMEOUT_SECONDS`).
- **Task scripts** (`modules/platform/tasks`):
  - `deploy`: resolves the commit (webhook deploys always build the branch head), downloads the tarball, rejects symlinks that leave the tree, writes `railpack.json` (and an empty `composer.json` so `RAILPACK_PHP_EXTENSIONS` applies), builds with `railpack prepare` + `buildctl` on a BuildKit daemon Pod started for this build only, pushes to `PLATFORM_REGISTRY`, places the database, applies `Manifests::tenant` + `Manifests::app` and waits for the rollout.
  - `rollback`: re-applies a previous image.
  - `destroy_project`: removes a deleted project's app (HTTPRoute, Middleware, Service, Deployment, MariaDB Grant/User/Database CRs, files PVC, database Secret), then releases its verified domain. Each object is read first and deleted only if it sits in the team's namespace and carries the project's ownership labels (`managed-by=trongate-cloud`, `project-id`, `app.kubernetes.io/name`), pinned to the checked uid. Keeps the namespace, the database (Database CR `cleanupPolicy: Skip`), the files (`reclaimPolicy: Retain`), the images and the soft-deleted row (slug and history).
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
  - Project settings: custom domain with DNS verification, persistent paths, extra PHP extensions, always-on, and the GitHub webhook URL and secret.
  - Delete project: a confirmation page (type the slug, CSRF-protected) soft-deletes the project and queues `destroy_project`. Refused while a deployment is running; queued deploys are cancelled.
  - Deployment page: logs polled with MX.
  - Team switcher: CSRF-protected.
- **Custom domains**: platform hosts (`BASE_URL`, `PLATFORM_REGISTRY` and their subdomains) are rejected, and a domain is routed only once its TXT record verifies it. The admission policy also blocks platform hostnames on tenant HTTPRoutes.
- **GitHub webhooks** (`POST /webhooks/github/{project_id}`):
  - The `X-Hub-Signature-256` HMAC is checked against a secret derived from the stored seed and `APP_KEY`, so a database leak alone does not reveal it.
  - Answers `ping`.
  - Queues a deploy when a push hits the project's branch. A replayed push cannot roll an app back, because deploys always build the branch head.
- **Metrics**: `app_tasks`, `app_deployments`, `app_live_projects`.
- **Admin MCP** (`POST /mcp`, `modules/mcp`): one `MCP_SECRET` bearer secret (in the app-only `trongate-cloud-mcp` Secret), tools across all teams, including `audit_app_resources`: a read-only list of labelled per-project objects that no longer match a live project (it never repairs). See `docs/mcp.md`.
- **Cluster manifests** (`deploy/k8s`, see its README):
  - Operators; the shared MariaDB, Valkey and JuiceFS.
  - BuildKit: a namespace, quota and NetworkPolicy; daemons are created per build.
  - Sablier.
  - Platform: the app, the worker, and its own MariaDB `platform`, which tenants cannot reach.
  - App access: a cluster-wide read-only role on Deployments and Pods for live app state, and on the other per-project kinds (no Secrets) for the orphan audit (`platform/app-rbac.yaml`).
  - Worker access: RBAC with write-only Secrets, plus admission policies that limit where the worker writes, what the objects contain, and that it only deletes objects carrying its labels (a project's `p{id}-*` objects only with that project's id label; this is the only check for Secrets, which the worker cannot read).

Still open:

- **Not wired yet:**
  - Released files PVs and kept `tc_p{id}` databases of deleted projects pile up until removed by hand; there is no purge step yet.
  - No CI workflow builds or pushes the platform images.
  - Valkey session/cache wiring for tenants (per-tenant ACL users).
  - Backups.
  - Image pull credentials for `PLATFORM_REGISTRY` in tenant namespaces.
- **Hardening:**
  - Push credentials are one registry-wide token that buildctl forwards into each build daemon. Scope them per `p{id}` repository with a registry token service.
  - BuildKit mTLS. Today only the NetworkPolicy and the per-build daemon protect it.
  - JuiceFS metadata still shares `shared-1` with tenant databases.
- **Needs a live cluster:**
  - Cross-namespace `mariaDbRef`.
  - The platform MariaDB passing restricted Pod Security.
  - The CEL admission policies (including `trongate-worker-owned-deletes`).
  - Railpack's FrankenPHP image running as uid 1000 on port 8080.
  - The empty `composer.json` approach.
  - Per-build buildkitd start-up time.
  - Everything on the Spike list.
