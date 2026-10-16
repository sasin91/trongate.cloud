-- A 'step' line marks where a deploy enters one of its steps (its message
-- is the step's key, Deploy_steps::KEYS); the deployment page groups the
-- log lines under the step they belong to.
ALTER TABLE task_logs
  MODIFY level ENUM('info', 'warn', 'error', 'step') DEFAULT 'info';
