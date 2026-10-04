# Admin MCP server

Trongate Cloud has a built-in [Model Context Protocol](https://modelcontextprotocol.io) server for platform admins. With it, Claude can list projects across all teams, read deployment logs, deploy and roll back. It is not a customer feature.

- **Endpoint:** `POST {BASE_URL}mcp` (Streamable HTTP; responses are always `application/json`, never SSE).
- **Protocol versions:** `2025-06-18` and `2025-03-26`. JSON-RPC batches are only accepted under `2025-03-26`.
- **Auth:** `Authorization: Bearer <MCP_SECRET>`. One shared secret from the environment; no tokens table, no OAuth.
- **Stateless:** `initialize` returns an `Mcp-Session-Id`, but the server never requires it. `GET` and `DELETE` return `405`.

## 1. Set the secret

```sh
php -r "echo bin2hex(random_bytes(32));"
```

Put it in `MCP_SECRET` (`.env` locally, the SOPS-encrypted, app-only `trongate-cloud-mcp` Secret in the cluster). `scripts/setup-env.sh` generates one. While `MCP_SECRET` is unset or shorter than 32 characters, every request gets `401`. Rotate it by changing the value and restarting the app.

## 2. Connect Claude Code

```sh
claude mcp add --transport http --scope user trongate https://trongate.cloud/mcp \
  --header "Authorization: Bearer $TRONGATE_MCP_SECRET"
```

Run `/mcp` inside Claude Code to check that the server is connected.

### From your phone

Start Claude Code on your machine with the server configured, then enable **Remote Control** (`/remote-control`). Continue that session from the Claude app and ask things like "is the shop deploy done?" or "roll back the blog". The secret never leaves your machine.

## Tools

| Tool | Arguments | Notes |
|---|---|---|
| `list_projects` | `team_id?` | All teams (or one): URLs, source, current deployment status, team name |
| `get_project` | `project_id` | Settings, persistent paths, current deployment |
| `list_deployments` | `project_id`, `limit?` (1–100, default 10) | Newest first |
| `get_deployment_logs` | `deployment_id`, `after_id?` | Up to 200 lines. Pass `next_after_id` as `after_id` to follow a running deployment; `finished` becomes true when it ends |
| `platform_status` | `team_id?` | Projects and deployments by status, deployments in progress, recent failures |
| `deploy_project` | `project_id`, `commit_sha?` | Queues a deployment (`trigger_type = mcp`, no team member) and returns its id |
| `rollback_project` | `project_id`, `deployment_id?` | Redeploys an earlier successful image. Without `deployment_id`, it rolls back to the one before the current deployment |
| `audit_app_resources` | | Read-only: per-project objects in tenant namespaces (HTTPRoute, Middleware, Service, Deployment, MariaDB CRs, PVC) that carry trongate.cloud's labels but match no live project, e.g. after a failed `destroy_project`. Never repairs; Secrets are not checked |

- **Annotations:** read tools are marked `readOnlyHint`; `deploy_project` and `rollback_project` are marked `destructiveHint`, so clients ask before running them.
- **Results:** pretty-printed JSON text. Under `2025-06-18` the same data is also sent as `structuredContent`.
- **Tool errors** (bad arguments, not found) come back as `isError: true` results. An unknown tool is JSON-RPC `-32602`.

## Security

- The secret is compared with `hash_equals` over sha256 digests. It is never logged or returned.
- Tools resolve a project's or deployment's team first and then go through `Platform_actions` with that team id. `webhook_secret` and other internal columns are never returned.
- Missing or wrong secret: `401`. Unsupported `MCP-Protocol-Version`: `400`. Body over 1 MB: `413`.
- **DNS rebinding:** a request with an `Origin` header must match `BASE_URL`'s origin or an entry in `MCP_ALLOWED_ORIGINS` (comma separated), otherwise `403`. CLI clients send no `Origin`.
- The web server must pass the `Authorization` header to PHP. Nginx/Herd and FrankenPHP do by default.

## Code map

- `modules/mcp/Mcp.php`: transport, JSON-RPC and auth.
- `modules/mcp/traits/Mcp_tools.php`: tool definitions and handlers. To add a tool, add its name to `TOOLS`, a definition and a `_tool_{name}()` method.
- `modules/mcp/Mcp_model.php`: `Platform_actions` + team lookups + `platform_status()`.
