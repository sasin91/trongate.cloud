-- Project deletion.
--
-- Deleting a project soft-deletes its row and queues the destroy_project task
-- (Platform_actions::queue_project_delete). A deleted project disappears from
-- the dashboard, the admin MCP and webhooks, and no deploy can be queued for it.
--
-- The row stays: it keeps the slug (so {slug}.PLATFORM_APPS_DOMAIN is never
-- reused while the old route may still exist), the deployment history and the
-- database_server placement of the database that destroy_project keeps.

ALTER TABLE project
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL,
  ADD INDEX idx_team_deleted (team_id, deleted_at);
