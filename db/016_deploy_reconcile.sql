-- Backstop for GitHub push webhooks that never arrive.
--
-- GitHub does not retry a delivery that failed to connect, so a push can be
-- missed silently. The worker's periodic reconcile_deploys task compares each
-- auto-deploying project's branch head with its deployments and queues a
-- deploy (trigger 'reconcile') for a head that was never built.
--
-- Periodic tasks (Runtime::PERIODIC_TASKS) are platform-wide, so they run as
-- tasks rows without a team. scheduled_jobs remembers when each was last
-- queued (the claim that keeps several workers from queueing it twice) and the
-- summary of its last run, which /metrics exposes.

ALTER TABLE deployment
  MODIFY trigger_type ENUM('manual', 'webhook', 'mcp', 'rollback', 'reconcile') NOT NULL DEFAULT 'manual';

ALTER TABLE tasks
  MODIFY team_id INT NULL COMMENT 'NULL for platform-wide periodic tasks';

CREATE TABLE IF NOT EXISTS scheduled_jobs (
  script VARCHAR(100) PRIMARY KEY COMMENT 'modules/platform/tasks/{script}.php',
  last_queued_at TIMESTAMP NULL,
  last_finished_at TIMESTAMP NULL,
  last_summary JSON NULL
);
