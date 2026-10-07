# MCP server

Trongate Cloud has a built-in [Model Context Protocol](https://modelcontextprotocol.io) server, so an assistant such as Claude can do what a customer does in the control panel: create projects and apps, deploy and roll back, read build and app logs, change settings and environment variables, and read and change an app's database.

- **Endpoint:** `POST {BASE_URL}mcp` (Streamable HTTP; responses are always `application/json`, never SSE).
- **Protocol versions:** `2025-06-18` and `2025-03-26`. JSON-RPC batches are only accepted under `2025-03-26`.
- **Stateless:** `initialize` returns an `Mcp-Session-Id`, but the server never requires it. `GET` and `DELETE` return `405`.
- **Two kinds of token:**
  - an **OAuth access token**: a customer connected an assistant. The tools act as that customer, in the projects they are a member of.
  - the **admin secret** (`MCP_SECRET`): every project, plus the admin tools (audit and retry cleanups). Not a customer feature.

## Connecting as a customer (OAuth)

Customers never paste tokens. The assistant finds everything from the endpoint:

1. `POST /mcp` without a token returns `401` with `WWW-Authenticate: Bearer resource_metadata="{BASE_URL}.well-known/oauth-protected-resource/mcp"` (RFC 9728).
2. That document names this server as the authorization server; `/.well-known/oauth-authorization-server` (RFC 8414) lists the endpoints.
3. The assistant registers itself at `POST /oauth/register` (dynamic client registration, RFC 7591). Its redirect URIs must belong to a supported assistant (an `Mcp_provider`, below), otherwise `400 invalid_redirect_uri`.
4. `GET /oauth/authorize` (PKCE `S256` required) checks the request, keeps it in a signed cookie for 10 minutes and shows `/oauth/consent`. Signed out, the customer signs in with GitHub and comes back. **Allow** sends a code back to the assistant.
5. `POST /oauth/token` swaps the code (with `code_verifier`) for an access token (1 hour) and a refresh token (30 days, rotated on every use).

The customer sees and disconnects their connections on `integrations/claude`. Disconnecting revokes the grant and deletes its tokens at once.

Safety:

- Tokens, codes and client secrets are stored only as sha256. Codes and refresh tokens are single use: presenting a spent one again revokes the whole grant.
- An access token works only while its grant is live and the customer is active and onboarded. Membership is checked on every call, like the panel does.
- Clients nobody connected within a day are deleted; registrations are capped per hour.

### Tables

`mcp_oauth_client`, `mcp_oauth_grant` and `mcp_oauth_token` (see `db/schema.sql`). Apply them by hand in production before deploying.

## Assistants (providers)

The OAuth server and the tools are shared. Each assistant is a child module of `mcp` with an `Mcp_provider` (`modules/mcp/providers/Mcp_provider.php`) that says which redirect URIs are its own and where its setup page is, and is listed in `Mcp_providers::PROVIDERS`.

- **Claude** (`modules/mcp/claude`): `https://claude.ai/api/mcp/auth_callback`, `https://claude.com/api/mcp/auth_callback` and loopback URIs (Claude Code). Page: `integrations/claude`.
- **Adding OpenAI later:** `modules/mcp/openai` with an `Openai_provider` accepting ChatGPT's callback, its own page, one line in `PROVIDERS` and its routes.

### Claude (web, desktop, phone)

Settings, Connectors, Add custom connector, URL `{BASE_URL}mcp`, then Connect and Allow.

### Claude Code

```sh
claude mcp add --transport http trongate https://trongate.cloud/mcp
```

Then `/mcp`, pick `trongate` and sign in.

## Tools

Customer tools act as the signed-in customer. Write tools carry `destructiveHint`, so assistants ask before running them.

| Tool | Who | Notes |
|---|---|---|
| `list_projects` | customer | Projects, role, app count |
| `create_project` | customer | Owned by the customer |
| `list_apps` | both | `project_id?` |
| `get_app` | both | Settings, custom domain status and its TXT record, live state, current deployment, database name, env var names (customer) |
| `create_app` | customer | From a repository the GitHub App can read for the customer (needs their GitHub sign-in); deploys it unless `deploy: false` |
| `update_app_settings` | customer | Only the fields passed change (`domain`, `persistent_paths`, `php_extensions`, `always_on`, `auto_deploy`, `trongate_env`); same rules as the Settings page (`App_rules`) |
| `verify_domain` | customer | Checks the TXT record |
| `list_deployments`, `get_deployment_logs` | both | Build and rollout logs, followable with `after_id` |
| `deploy_app`, `rollback_app` | both | Recorded as `trigger_type = mcp`, with the customer |
| `get_app_logs` | both | The running app's output (`App_logs`); never wakes an asleep app; `problems_only` |
| `list_env_vars`, `set_env_var`, `delete_env_var` | customer | Values are write-only |
| `list_tables`, `describe_table`, `browse_table` | customer | The app's own database, as the app's own user |
| `query_database` | customer | Statements run in one read-only transaction; one result per statement; same allowlist and limits as the Database tab |
| `execute_sql` | customer | Writes allowed ("Allow changes"), all statements in one transaction; backs up the tables it names first unless `backup: false` |
| `insert_row`, `update_row`, `delete_row` | customer | One row by primary key; `update_row` and `delete_row` back up the row first unless `backup: false` |
| `list_backups`, `restore_backup` | customer | The Database tab's Backups (kept 7 days); restoring puts the tables or row back as they were |
| `database_activity` | customer | The app's `app_database_log`, with the backup each change took |
| `platform_status` | admin | All projects or one |
| `audit_app_resources`, `retry_app_cleanup`, `retry_project_cleanup` | admin | See below |

Deleting apps or projects and managing members stay in the control panel.

Database tools share the Database tab's guardrails (`App_database_guard`, `App_database_log`): statement allowlist (`App_database::refusal`), 15 s for the whole run, 500 rows / 2 MB, one run at a time and 30 per minute per customer, backups in `app_database_backup` with the tab's size caps, and every run, row change and restore in `app_database_log`, shown under Recent activity on the Database tab. If one statement of several fails, the others are rolled back, except what a CREATE, ALTER, DROP, TRUNCATE or RENAME already committed (the error says which).

- `audit_app_resources`: per-app objects in tenant namespaces that carry trongate.cloud's labels but match no live app, deleted apps whose `destroy_app` did not finish (`unfinished_cleanups`), and deleted projects whose `destroy_project` did not finish. Never repairs; Secrets are not listed.
- `retry_app_cleanup` / `retry_project_cleanup`: queue the cleanup again (refused while one is queued or running).

Results are pretty-printed JSON text; under `2025-06-18` the same data is also sent as `structuredContent`. Tool errors (bad arguments, not found, refused SQL) come back as `isError: true` results. An unknown tool is JSON-RPC `-32602`.

## Admin secret

```sh
php -r "echo bin2hex(random_bytes(32));"
```

Put it in `MCP_SECRET` (`.env` locally, the SOPS-encrypted `trongate-cloud-mcp` Secret in the cluster). While it is unset or shorter than 32 characters, the admin secret is never accepted. It is compared with `hash_equals` over sha256 digests.

```sh
claude mcp add --transport http --scope user trongate-admin https://trongate.cloud/mcp \
  --header "Authorization: Bearer $TRONGATE_MCP_SECRET"
```

## Security

- Missing or wrong token: `401`. Unsupported `MCP-Protocol-Version`: `400`. Body over 1 MB: `413`.
- **DNS rebinding:** a request with an `Origin` header must match `BASE_URL`'s origin or an entry in `MCP_ALLOWED_ORIGINS` (comma separated), otherwise `403`.
- The web server must pass the `Authorization` header to PHP. Nginx/Herd and FrankenPHP do by default.

## Code map

- `modules/mcp/Mcp.php`: transport, JSON-RPC, authentication (admin secret or access token).
- `modules/mcp/traits/Mcp_tools.php`: tool registry and the app tools; `Mcp_database_tools.php`, `Mcp_admin_tools.php`: the other groups. Each definition lists its `scopes`. Add a tool with a definition and a `_tool_{name}()` method.
- `modules/mcp/traits/Mcp_grants.php`: tokens and grants (model trait).
- `modules/mcp/oauth/`: the OAuth server (`mcp-oauth`) and the consent page.
- `modules/mcp/providers/Mcp_provider.php`: the provider interface and registry.
- `modules/mcp/claude/`: Claude's provider and page (`mcp-claude`).
