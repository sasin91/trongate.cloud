# Admin MCP server

Trongate Cloud has a built-in [Model Context Protocol](https://modelcontextprotocol.io) server for platform admins. With it, Claude can list apps across all projects, read deployment logs, deploy and roll back. It is not a customer feature.

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
| `list_apps` | `project_id?` | All projects (or one): URLs, source, current deployment status, project id and name |
| `get_app` | `app_id` | Settings, persistent paths, current deployment |
| `list_deployments` | `app_id`, `limit?` (1–100, default 10) | Newest first |
| `get_deployment_logs` | `deployment_id`, `after_id?` | Up to 200 lines. Pass `next_after_id` as `after_id` to follow a running deployment; `finished` becomes true when it ends |
| `platform_status` | `project_id?` | Apps and deployments by status, deployments in progress, recent failures |
| `deploy_app` | `app_id`, `commit_sha?` | Queues a deployment (`trigger_type = mcp`, no project member) and returns its id |
| `rollback_app` | `app_id`, `deployment_id?` | Redeploys an earlier successful image. Without `deployment_id`, it rolls back to the one before the current deployment |
| `audit_app_resources` | | Read-only: per-app objects in tenant namespaces (HTTPRoute, Middleware, Service, Deployment, MariaDB CRs, PVC) that carry trongate.cloud's labels but match no live app in their project's namespace, e.g. after a failed `destroy_app`; deleted apps whose `destroy_app` failed, is still pending, or was never queued (`unfinished_cleanups`); and deleted projects whose namespace removal (`destroy_project`) did not finish (`unfinished_project_cleanups`). Never repairs; Secrets are not listed |
| `retry_app_cleanup` | `app_id` | Queues `destroy_app` again for a deleted app (refused while one is queued or running). The only way to finish a failed app cleanup: a deleted app is hidden from every other tool and the UI, and the worker does not retry |
| `retry_project_cleanup` | `project_id` | Queues `destroy_project` again for a deleted project whose namespace is still there (refused while one is queued or running). It deletes the namespace only if no app object is left in it |

- **Annotations:** read tools are marked `readOnlyHint`; `deploy_app`, `rollback_app`, `retry_app_cleanup` and `retry_project_cleanup` are marked `destructiveHint`, so clients ask before running them.
- **Results:** pretty-printed JSON text. Under `2025-06-18` the same data is also sent as `structuredContent`.
- **Tool errors** (bad arguments, not found) come back as `isError: true` results. An unknown tool is JSON-RPC `-32602`.

## Security

- The secret is compared with `hash_equals` over sha256 digests. It is never logged or returned.
- Tools resolve an app's or deployment's project first and then go through `Platform_actions` with that project id. Internal columns are never returned.
- Missing or wrong secret: `401`. Unsupported `MCP-Protocol-Version`: `400`. Body over 1 MB: `413`.
- **DNS rebinding:** a request with an `Origin` header must match `BASE_URL`'s origin or an entry in `MCP_ALLOWED_ORIGINS` (comma separated), otherwise `403`. CLI clients send no `Origin`.
- The web server must pass the `Authorization` header to PHP. Nginx/Herd and FrankenPHP do by default.

## Code map

- `modules/mcp/Mcp.php`: transport, JSON-RPC and auth.
- `modules/mcp/traits/Mcp_tools.php`: tool definitions and handlers. To add a tool, add its name to `TOOLS`, a definition and a `_tool_{name}()` method.
- `modules/mcp/Mcp_model.php`: `Platform_actions` + project lookups + `platform_status()`.
