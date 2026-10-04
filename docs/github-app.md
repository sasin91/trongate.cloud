# The trongate.cloud GitHub App

One GitHub App does everything trongate.cloud needs from GitHub:

- **Sign-in.** Its OAuth client is "Continue with GitHub" on the login and
  register pages, and "Connect GitHub" in onboarding (`modules/github_auth`).
  The user access token it returns is stored encrypted (`secrets`, module
  `customer`, service `github`) and only lists and checks the repositories a
  customer may pick.
- **Repository access.** Customers install the App on the repositories they
  want to deploy. The worker reads them with an installation token scoped to
  one repository and read-only contents (`Github_app::repository_token`), so a
  build never depends on who added the app or whether they are still signed in.
- **Push deploys.** The App's webhook (`POST /webhooks/github`) delivers every
  push on every installation. There is nothing for customers to set up per
  repository.

No customer ever pastes a token.

## Register it

Registering needs the owner's GitHub account (a personal account works; an
organization can own it later via a transfer).

1. Generate a webhook secret: `openssl rand -hex 32`.
2. Open this link, which fills in everything except the webhook secret:

   <https://github.com/settings/apps/new?name=trongate.cloud&description=Deploys+your+Trongate+apps+on+trongate.cloud.+An+independent+service%2C+not+affiliated+with+or+endorsed+by+the+Trongate+project.&url=https%3A%2F%2Ftrongate.cloud%2F&callback_urls%5B%5D=https%3A%2F%2Ftrongate.cloud%2Fauth%2Fgithub%2Fcallback&request_oauth_on_install=false&setup_url=https%3A%2F%2Ftrongate.cloud%2Fauth%2Fgithub%2Finstalled&setup_on_update=true&webhook_active=true&webhook_url=https%3A%2F%2Ftrongate.cloud%2Fwebhooks%2Fgithub&events%5B%5D=push&contents=read&metadata=read&emails=read&public=true>

3. Paste the webhook secret, check the settings against the table below, and
   create the App.
4. On the App's page: note the **Client ID** and the slug (the last part of
   its public URL, `github.com/apps/<slug>`), **Generate a new client secret**,
   and **Generate a private key** (downloads a `.pem`).

| Setting | Value |
| --- | --- |
| GitHub App name | `trongate.cloud` (any free name; the slug follows it) |
| Homepage URL | `https://trongate.cloud/` |
| Callback URL | `https://trongate.cloud/auth/github/callback` |
| Expire user authorization tokens | on (the app renews them) |
| Request user authorization (OAuth) during installation | **off** |
| Enable Device Flow | off |
| Setup URL | `https://trongate.cloud/auth/github/installed` |
| Redirect on update | on |
| Webhook | Active, URL `https://trongate.cloud/webhooks/github`, the secret from step 1 |
| Repository permissions | Contents: **Read-only**, Metadata: Read-only (required) |
| Account permissions | Email addresses: **Read-only** (sign-up needs a verified email) |
| Subscribe to events | **Push** |
| Where can this GitHub App be installed? | **Any account** |

"During installation" stays off on purpose: customers sign in first, then
install; the Setup URL brings them back to the page they started from.

## Configure the platform

Five values, the same names locally (`.env`) and in the cluster (the
`trongate-cloud-github` Secret, SOPS-encrypted, see
`deploy/k8s/platform/secrets.example.yaml`; the app and the worker both load it):

| Variable | From |
| --- | --- |
| `GITHUB_APP_SLUG` | the App's public URL, `github.com/apps/<slug>` |
| `GITHUB_APP_CLIENT_ID` | the App's page (starts with `Iv`) |
| `GITHUB_APP_CLIENT_SECRET` | "Generate a new client secret" |
| `GITHUB_APP_WEBHOOK_SECRET` | step 1 |
| `GITHUB_APP_PRIVATE_KEY` | the `.pem` file's contents (in `.env`, one line with literal `\n` is fine) |

Without them the login page has no GitHub button, the webhook answers 503 and
builds fail with a message naming the missing setting.

For local development, register a second App with `BASE_URL`-based URLs
(webhooks need a tunnel to reach your machine; everything else works on
`https://trongate.cloud.test/`).

## How the pieces fit

- `modules/services/clients/Github_app.php`: the App. OAuth (authorize URL,
  code exchange, refresh) and its RS256 JWT for installation tokens.
  Framework-free, so the worker loads it.
- `modules/services/clients/Github.php`: REST calls with one token (a user
  access token or an installation token): the user, their verified email,
  the repositories on their installations, branches, commits, tarballs.
- `modules/github_auth`: `auth/github` (start), `auth/github/callback`,
  `auth/github/installed` (the Setup URL). The OAuth state is HMAC-signed with
  `APP_KEY` and bound to a short-lived cookie, so it works whichever app pod
  answers the callback.
- `Services::for_customer()`: the customer's user access token, renewed when it
  has expired; a refused renewal forgets it and the customer connects again.
- `modules/webhooks`: verifies the signature and queues a deploy for every app
  on the pushed repository and branch with Deploy on push on.
- `deploy.php` and `Deploy_reconcile`: `Platform_task::github_client($repo)`.
  If the App is not installed on the repository, the deploy fails with that
  message and the reconcile skips the app (`not-installed`).

## Accounts

- A GitHub account links to at most one customer (`customer.github_user_id`).
- Signed out, an unlinked GitHub account signs up with its verified primary
  email. If a password account already uses that email, it is not taken over:
  the customer signs in with the password and connects GitHub from onboarding
  or a new app, which links the account.
- GitHub sign-ups have no password, so the password form never signs them in.
