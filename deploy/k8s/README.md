# deploy/k8s

Flux-managed manifests for the Trongate Cloud platform on the k3s cluster from
`hcloud-k3s-platform-kit`. Tenants (a namespace per project, `project-{id}`,
and per-app resources) are
**not** here: the worker creates them through the Kubernetes API
(`modules/platform/manifests/Manifests.php`). See `docs/platform-architecture.md`
for the decisions behind this layout.

| Directory | What | Namespace |
|---|---|---|
| `operators/` | mariadb-operator (+ CRDs), JuiceFS CSI driver (Flux HelmReleases) | `mariadb-operator`, `kube-system` |
| `databases/` | Shared MariaDB `shared-1` (tenant DBs, JuiceFS metadata) | `databases` |
| `cache/` | Shared Valkey (sessions/cache, ACL-only) | `cache` |
| `files/` | JuiceFS metadata DB CRs + the `tenant-files` RWX StorageClass | `files` |
| `buildkit/` | Namespace, quota, NetworkPolicy and worker Role for the per-build BuildKit daemons | `buildkit` |
| `sablier/` | Sablier (scale-to-zero API for the Traefik plugin) | `sablier` |
| `platform/` | Web app, task worker, mail inbox (`mail.yaml`), config, RBAC, admission policies, the platform's own MariaDB `platform` + `tcloud` DB | `trongate-cloud` |

`kustomization.yaml` in this directory includes everything except `operators/`,
which is a separate Flux Kustomization so its CRDs exist first.

## Prerequisites

From the platform kit (or installed separately):

- k3s **>= 1.30** (`platform/worker-admission-policy.yaml` uses `ValidatingAdmissionPolicy`).
  Keep the hostnames in that file in sync with `BASE_URL`, `PLATFORM_APPS_DOMAIN`
  and `PLATFORM_REGISTRY` if you change them.
- Flux with SOPS decryption (an age key in `flux-system/sops-age`).
- `hcloud-volumes` StorageClass (Hetzner CSI), used by MariaDB and Valkey.
- Traefik with the Gateway API provider and a Gateway `platform` in
  namespace `traefik` (`PLATFORM_GATEWAY_NAME` / `PLATFORM_GATEWAY_NAMESPACE`), with
  HTTPS listeners for `trongate.cloud` (`sectionName: https-platform`) and `*.trongate.dev` (wildcard
  certificate, so DNS-01) that allow routes from all namespaces.
- cert-manager with Gateway API support (`config.gatewayAPI.enabled`) and a
  ClusterIssuer `letsencrypt-tenants` (`PLATFORM_DOMAIN_ISSUER`) with only an
  HTTP-01 `gatewayHTTPRoute` solver whose parentRef is the Gateway
  `acme-http01` **without a namespace** (`Manifests::ACME_GATEWAY`), for
  custom-domain certificates. The infra repository's
  `platform/configs/cluster-issuers.yaml` has it. (Not in this directory: a
  cluster-scoped custom resource would get the `trongate-cloud` namespace from
  `platform/kustomization.yaml`.)
- The Traefik namespace labelled `platform.example.com/role=gateway`: tenant
  NetworkPolicies only admit ingress from namespaces with that label (and from
  `sablier`).
- The Sablier Traefik plugin enabled under the name `sablier`
  (`Manifests::SABLIER_PLUGIN`), e.g. Traefik Helm values:

  ```yaml
  experimental:
    plugins:
      sablier:
        moduleName: github.com/sablierapp/sablier-traefik-plugin
        version: v1.3.1
  providers:
    kubernetesCRD:
      allowCrossNamespace: false
  ```

- DNS: `trongate.cloud`, `trongate.dev` and `*.trongate.dev` pointing at the ingress,
  whose addresses are `PLATFORM_INGRESS_IPV4`/`PLATFORM_INGRESS_IPV6` in
  `platform/config.yaml` (custom domains are routed once they point there).
- A Hetzner Object Storage bucket for JuiceFS.
- An OCI registry for built app images (`PLATFORM_REGISTRY`, default
  `registry.trongate.cloud/tc`). The per-build buildkitd Pods push to it over the
  public internet (their NetworkPolicy only allows 80/443 to non-private
  addresses), and nodes pull from it.
- The platform images `registry.trongate.cloud/platform/trongate-cloud` and
  `registry.trongate.cloud/platform/trongate-cloud-worker` (from `Dockerfile`
  and `Dockerfile.worker`; tags in `platform/kustomization.yaml`), pushed to
  the same registry by the worker on every push to main (see "Deploying the
  platform" below), or by hand with `bin/push-images.sh` (linux/amd64, built
  locally with Docker; log in once as the registry's `push` user).

## Secrets (SOPS)

Every Secret has a `secrets.example.yaml` next to the manifests that use it. Those
files are **examples only** and are deliberately not listed in any kustomization.
For each one: copy it to `secrets.sops.yaml` in the same directory, fill in real
values, encrypt it, and add `secrets.sops.yaml` to that directory's
`kustomization.yaml` resources (or keep the encrypted files in the platform
kit's cluster repository instead).

```sh
sops --encrypt --age <age-public-key> --encrypted-regex '^(data|stringData)$' \
  --in-place deploy/k8s/platform/secrets.sops.yaml
```

Never commit a filled-in file before it is encrypted.

| Secret | Namespace | Keys | Notes |
|---|---|---|---|
| `mariadb-shared-1` | `databases` | `root-password` | `openssl rand -hex 32` |
| `mariadb-platform` | `trongate-cloud` | `root-password` | root of the platform MariaDB; `openssl rand -hex 32` |
| `valkey-acl` | `cache` | `users.acl` | default user off; admin and worker (`trongate-cloud`) passwords `openssl rand -hex 32` |
| `juicefs-meta` | `files` | `password` | MariaDB user `juicefs`; must match the password in `juicefs-secret.metaurl` |
| `juicefs-secret` | `files` | `name`, `metaurl`, `storage`, `bucket`, `access-key`, `secret-key`, `format-options` | Hetzner Object Storage credentials |
| `trongate-cloud-env` | `trongate-cloud` | `APP_KEY`, `DB_PASSWORD` | shared by app and worker, see below |
| `trongate-cloud-mcp` | `trongate-cloud` | `MCP_SECRET` | app only (optional; without it MCP is disabled) |
| `trongate-cloud-github` | `trongate-cloud` | `GITHUB_APP_SLUG`, `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_WEBHOOK_SECRET`, `GITHUB_APP_PRIVATE_KEY` | the GitHub App, app and worker; see `docs/github-app.md` |
| `trongate-cloud-mail` | `trongate-cloud` | `MAIL_KEY`, `MAIL_DB_PASSWORD` | mail inbox (`platform/mail.yaml`) and worker; `MAIL_KEY` signs the apps' inbox passwords, `MAIL_DB_PASSWORD` is the MariaDB user `tcloud_mail`. Both `openssl rand -hex 32`; must exist before mail capture is deployed |
| `trongate-cloud-valkey` | `trongate-cloud` | `VALKEY_WORKER_PASSWORD` | worker only; the `trongate-cloud` user's password in `valkey-acl`. Without it customer apps keep file sessions in the pod |
| `registry-push` | `trongate-cloud` | `.dockerconfigjson` | push credentials for `PLATFORM_REGISTRY`, mounted into the worker |

`trongate-cloud-env`:

- `APP_KEY`: `php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"`.
  Encrypts stored GitHub sign-in tokens, signs the GitHub sign-in state and
  derives every tenant database password and domain verification token. The
  app and the worker must share it; rotating it makes stored tokens unreadable
  (customers connect GitHub again) and rotates tenant DB passwords on their
  next deploy.
- `DB_PASSWORD`: password of the MariaDB user `tcloud` (`platform/database.yaml`
  points the User CR at this key).

`trongate-cloud-mcp` (app only, so the worker never sees it):

- `MCP_SECRET`: bearer secret for the admin MCP endpoint (`POST /mcp`, see
  `docs/mcp.md`). At least 32 characters: `openssl rand -hex 32`. Unset or shorter
  disables the endpoint (every request gets 401). Rotate by changing the value and
  restarting the app Deployment.

`scripts/setup-env.sh` generates `APP_KEY`, `MCP_SECRET` and `DB_PASSWORD` into a
local `.env`; copy those values into the encrypted `trongate-cloud-env` and
`trongate-cloud-mcp`.

## Bootstrap order

1. **Operators.** A Flux Kustomization for `deploy/k8s/operators` (skip what the
   platform kit already installs). Wait until the mariadb-operator and JuiceFS CSI
   HelmReleases are ready.
2. **Secrets.** Create the encrypted `secrets.sops.yaml` files above.
3. **Platform.** A Flux Kustomization for `deploy/k8s` with `dependsOn` the
   operators one and SOPS decryption:

   ```yaml
   apiVersion: kustomize.toolkit.fluxcd.io/v1
   kind: Kustomization
   metadata:
     name: trongate-cloud
     namespace: flux-system
   spec:
     interval: 10m
     path: ./deploy/k8s
     prune: true
     sourceRef:
       kind: GitRepository
       name: trongate-cloud
     dependsOn:
       - name: trongate-cloud-operators
     decryption:
       provider: sops
       secretRef:
         name: sops-age
   ```

   Do not enable `postBuild` substitution for `files/storageclass.yaml` (its
   `pathPattern` uses `${...}`; the file opts out with an annotation).
4. **Schema.** Once MariaDB `platform` (namespace `trongate-cloud`) and the
   `tcloud` Database/User/Grant are ready, apply `db/schema.sql` to it once,
   e.g. through a port-forward to `platform` (as the CI `sql` job does). Its
   `CREATE DATABASE IF NOT EXISTS` leaves the operator's database alone; the
   tables are created without `IF NOT EXISTS`, so applying it twice fails
   instead of mixing schemas. `bin/import-db.sh` drops the database first and
   is for local dev only. The schema seeds the `shared-1` row in
   `database_server`, which deploys place app databases on.
5. **Check.** The app answers on `https://trongate.cloud/`; the worker logs
   `Poll loop started`. A first app deploy then exercises a per-build
   buildkitd Pod, the registry, the tenant namespace, the admission policies,
   mariadb-operator CRs, JuiceFS and Sablier.

## Deploying the platform

A push to `main` of this repository deploys it, built by the platform itself:

1. The GitHub App's push webhook (the App must be installed on this
   repository) matches `PLATFORM_SOURCE_REPOSITORY` / `PLATFORM_SOURCE_BRANCH`
   (`platform/config.yaml`) and queues a `build_platform` task, at most one
   waiting at a time.
2. The worker builds the head of `main`: `docker/app-base/Dockerfile`,
   `Dockerfile`, then `Dockerfile.worker`, linux/amd64, on a throwaway
   `buildkitd-p{task}` Pod (the same per-build daemon and push credential as
   customer builds). The app base, which every customer app is built `FROM`,
   goes to `PLATFORM_APP_BASE_IMAGE` (`app-base:php8.4`) and is used by the
   next deploy; the other two go to `PLATFORM_IMAGES_REGISTRY` as
   `:main-{commit time UTC, YYYYmmddHHMMSS}-{short sha}` and `:{short sha}`.
3. Flux image automation in the infra repository (ImageRepository and
   ImagePolicy on `trongate-cloud-worker`, newest `main-*` tag) commits that
   tag to both `newTag` lines of its `trongate-cloud` Kustomization, and Flux
   rolls out the app and the worker.

Manifest changes under `deploy/k8s` apply within a minute of the push, before
the new images exist, so keep them compatible with the running image. Schema
changes reach the live database through `db/migrations`, which the new
worker applies on startup before taking tasks (`Migrations`); the app pod may
start a few seconds before that.

Turning it off: suspend the ImageUpdateAutomation (`flux suspend image update
trongate-cloud`) to stop rollouts, or empty `PLATFORM_SOURCE_REPOSITORY` to
stop builds. `bin/push-images.sh` pushes the same `main-*` tag from a local
checkout, which Flux rolls out the same way.

## Open items (need a live cluster)

- BuildKit mTLS between the worker and its per-build buildkitd; today the
  NetworkPolicy limits who can connect, and each daemon lives for one build.
- Push credentials scoped per `p{id}` repository (a registry token service)
  instead of the one registry-wide `registry-push` token that buildctl forwards
  into every build daemon.
- The platform MariaDB under restricted Pod Security (`platform/mariadb.yaml`
  TODO), and the CEL expressions in `platform/worker-admission-policy.yaml`.
- Image pull credentials for `PLATFORM_REGISTRY` in tenant namespaces (or a
  registry that nodes can pull from anonymously / via k3s `registries.yaml`).
- MariaDB replication + MaxScale (`databases/mariadb.yaml` TODO).
- Verify cross-namespace `mariaDbRef` from tenant namespaces with the operator
  version in use.
- Restoring nightly backups from the panel (`Daily_backup`); today it is
  `composer backup:decrypt` and an import by hand.
