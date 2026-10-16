-- Trongate Cloud database schema.
--
-- The whole schema in one file, applied once to an empty database:
--   bin/import-db.sh            (local dev; drops and recreates tcloud first)
--   deploy/k8s/README.md        (cluster; the operator creates tcloud)
-- An existing database is upgraded by db/migrations/{NNNN}_{name}.sql, which
-- the worker applies on startup (Migrations). Change a table here AND add a
-- migration for it, then record the migration in schema_migrations at the end
-- of this file; CI (bin/check-migrations.php) checks both agree.
--
-- Model: a customer belongs to projects (project_member). A project is one
-- Kubernetes namespace and holds apps. An app is one repository, deployed as a
-- Deployment + Service with its own database (tc_p{app id}) and objects
-- (p{app id}-*). Deployments and worker tasks hang off the app.

CREATE DATABASE IF NOT EXISTS tcloud;
USE tcloud;

-- ---------------------------------------------------------------------------
-- Trongate framework: users, levels, admin login, tokens
-- ---------------------------------------------------------------------------

CREATE TABLE trongate_user_levels (
  id INT PRIMARY KEY AUTO_INCREMENT,
  level_title VARCHAR(125) DEFAULT ''
);

INSERT INTO trongate_user_levels (id, level_title) VALUES
(1, 'admin'),
(2, 'customer');

CREATE TABLE trongate_users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(32) DEFAULT NULL,
  user_level_id INT DEFAULT 0
);

-- Platform admin (tg-admin): none is seeded. Create one by hand, with a
-- password_hash() of a strong password, linked to a level-1 trongate_users row.
CREATE TABLE trongate_administrators (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(65) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  password VARCHAR(60) DEFAULT NULL,
  trongate_user_id INT DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  failed_login_attempts INT NOT NULL DEFAULT 0,
  last_failed_attempt INT NOT NULL DEFAULT 0,
  login_blocked_until INT NOT NULL DEFAULT 0,
  failed_login_ip VARCHAR(45) NOT NULL DEFAULT ''
);

CREATE TABLE trongate_tokens (
  id INT PRIMARY KEY AUTO_INCREMENT,
  token VARCHAR(125) DEFAULT NULL,
  user_id INT DEFAULT 0,
  expiry_date INT DEFAULT NULL,
  code VARCHAR(3) DEFAULT '0'
);

-- Login module (modules/login): lockouts and password resets
CREATE TABLE login_attempts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  target_table VARCHAR(255) NOT NULL,
  identifier VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  attempted_at INT NOT NULL,
  KEY idx_target_table (target_table(191)),
  KEY idx_identifier (identifier(191))
);

CREATE TABLE password_resets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  target_table VARCHAR(255) NOT NULL,
  identifier VARCHAR(255) NOT NULL,
  token VARCHAR(255) NOT NULL,
  expiry_date INT NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at INT NOT NULL,
  KEY idx_token (token(191))
);

-- ---------------------------------------------------------------------------
-- Customers, projects, members
-- ---------------------------------------------------------------------------

-- A customer signs in through the login module (user level 2) with a password,
-- or with GitHub (modules/github_auth). GitHub sign-ups have no password.
CREATE TABLE customer (
  id INT PRIMARY KEY AUTO_INCREMENT,
  trongate_user_id INT NOT NULL,
  email VARCHAR(255) DEFAULT NULL,
  password VARCHAR(60) DEFAULT NULL,
  github_user_id BIGINT UNSIGNED NULL,
  github_login VARCHAR(39) NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  onboarded_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_email (email),
  UNIQUE KEY unique_github_user (github_user_id),
  CONSTRAINT fk_customer_user FOREIGN KEY (trongate_user_id) REFERENCES trongate_users (id)
);

-- One Kubernetes namespace, "project-{id}" (Platform_actions::create_project)
CREATE TABLE project (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  namespace VARCHAR(63) NOT NULL,
  owner_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY unique_namespace (namespace),
  CONSTRAINT fk_project_owner FOREIGN KEY (owner_id) REFERENCES customer (id)
);

-- owner: manages members, renames, deletes. member: sees, deploys, configures apps.
CREATE TABLE project_member (
  id INT PRIMARY KEY AUTO_INCREMENT,
  project_id INT NOT NULL,
  customer_id INT NOT NULL,
  role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_project_customer (project_id, customer_id),
  CONSTRAINT fk_project_member_project FOREIGN KEY (project_id) REFERENCES project (id),
  CONSTRAINT fk_project_member_customer FOREIGN KEY (customer_id) REFERENCES customer (id)
);

-- Encrypted with APP_KEY. A customer's GitHub user access token, from signing in
-- with the GitHub App: (module 'customer', customer id, service 'github').
CREATE TABLE secrets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  module VARCHAR(50) NOT NULL,
  module_id INT NOT NULL,
  service VARCHAR(50) DEFAULT NULL,
  variables TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_module_id_service (module, module_id, service)
);

-- ---------------------------------------------------------------------------
-- Apps and deployments
-- ---------------------------------------------------------------------------

-- Shared MariaDB servers (mariadb-operator MariaDB CRs) that app databases are placed on
CREATE TABLE database_server (
  id INT PRIMARY KEY AUTO_INCREMENT,
  label VARCHAR(255) NOT NULL,
  host VARCHAR(255) NOT NULL,
  port INT NOT NULL DEFAULT 3306,
  operator_name VARCHAR(63) NOT NULL COMMENT 'MariaDB CR name',
  operator_namespace VARCHAR(63) NOT NULL DEFAULT 'databases',
  capacity INT NOT NULL DEFAULT 200 COMMENT 'Max app databases placed here',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO database_server (label, host, operator_name) VALUES
('shared-1', 'shared-1.databases.svc.cluster.local', 'shared-1');

-- Every statement run and row changed from an app's Database tab (modules/apps/database).
-- Who, what and the outcome; row edits keep the table, key and columns, not the values.
CREATE TABLE app_database_log (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  customer_id INT NOT NULL,
  action ENUM('query', 'insert', 'update', 'delete', 'restore', 'import') NOT NULL,
  statement TEXT NOT NULL,
  allow_changes TINYINT(1) NOT NULL DEFAULT 0,
  affected INT NULL,
  error VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_app (app_id, id),
  KEY idx_customer_time (customer_id, created_at)
);

-- Backups taken before a change from the Database tab, when the person asks for
-- one, to restore with one click. Kept here rather than on the apps' database
-- server; Database_model caps them per app and in total and expires them.
CREATE TABLE app_database_backup (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  customer_id INT NOT NULL,
  log_id BIGINT NULL COMMENT 'app_database_log entry of the change',
  summary VARCHAR(500) NOT NULL COMMENT 'tables, or the row',
  manifest MEDIUMBLOB NOT NULL COMMENT 'serialize()d structure, triggers, columns and keys per table',
  data LONGBLOB NOT NULL COMMENT 'rows, deflated (App_database::back_up)',
  `rows` INT NOT NULL,
  bytes INT NOT NULL COMMENT 'rows before compression',
  stored_bytes INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  restored_at DATETIME NULL,
  KEY idx_app (app_id, id),
  KEY idx_log (log_id),
  KEY idx_expires (expires_at)
);

-- .sql files imported from the Database tab (Sql_import): the browser uploads
-- them in parts (app_sql_import_chunk, encrypted with APP_KEY), the worker
-- checks every statement and, once the person runs it, runs them one by one
-- (tasks/import_sql.php). The parts are deleted once it ran, and a day after
-- an upload was abandoned or an import failed (tasks/check_sql_imports.php).
CREATE TABLE app_sql_import (
  id INT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  customer_id INT NOT NULL,
  task_id INT NULL COMMENT 'the latest import_sql task',
  log_id BIGINT NULL COMMENT 'app_database_log entry of the import',
  file_name VARCHAR(255) NOT NULL,
  bytes BIGINT UNSIGNED NOT NULL COMMENT 'the file as uploaded',
  compressed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'gzip',
  status ENUM('uploading', 'checking', 'checked', 'running', 'done', 'failed', 'stopped') NOT NULL DEFAULT 'uploading',
  sql_bytes BIGINT UNSIGNED NULL COMMENT 'the SQL, uncompressed; known once checked',
  total_statements INT UNSIGNED NULL COMMENT 'statements to run; known once checked',
  summary TEXT NULL COMMENT 'JSON: statements by first keyword, tables dropped or emptied',
  position BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'bytes of SQL checked or run',
  line INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'the line position is on',
  statement_number INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'statements up to position, run or skipped',
  statements_run INT UNSIGNED NOT NULL DEFAULT 0,
  statements_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  settings TEXT NULL COMMENT 'JSON: session settings so far (SET NAMES, ...), applied on each new connection',
  error TEXT NULL,
  can_continue TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'a failed import may carry on from the statement that failed',
  skip_first TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'carry on after the statement that failed instead',
  file_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  KEY idx_app (app_id, id),
  KEY idx_status (status, updated_at)
);

CREATE TABLE app_sql_import_chunk (
  import_id INT NOT NULL,
  seq INT UNSIGNED NOT NULL,
  bytes INT UNSIGNED NOT NULL COMMENT 'the part as uploaded',
  data MEDIUMBLOB NOT NULL COMMENT 'encrypted with APP_KEY (Sql_import::seal)',
  PRIMARY KEY (import_id, seq),
  CONSTRAINT fk_sql_import_chunk FOREIGN KEY (import_id) REFERENCES app_sql_import (id) ON DELETE CASCADE
);

-- Deleting an app soft-deletes the row: it keeps the slug ({slug}.PLATFORM_APPS_DOMAIN
-- is never reused), the deployment history and the database placement.
CREATE TABLE app (
  id INT PRIMARY KEY AUTO_INCREMENT,
  project_id INT NOT NULL,
  label VARCHAR(255) NOT NULL,
  slug VARCHAR(40) NOT NULL,
  -- Serves {slug}.PLATFORM_APPS_DOMAIN; 0 for a production app whose staging app took it
  serves_slug_host TINYINT(1) NOT NULL DEFAULT 1,
  type ENUM('trongate_v1', 'trongate_v2') NOT NULL,
  -- A staging app is a copy of its production app (production_app_id) with
  -- the same slug that deploys its own branch (tasks/split_staging.php);
  -- 'preview' is reserved for pull request previews, linked the same way
  environment ENUM('production', 'staging', 'preview') NOT NULL DEFAULT 'production',
  production_app_id INT NULL,

  -- Custom domain: as entered, then proven by a TXT record, pointed here, and secured
  domain VARCHAR(255) NULL,
  verified_domain VARCHAR(253) NULL,
  domain_pointed_at TIMESTAMP NULL DEFAULT NULL,
  domain_secured_at TIMESTAMP NULL DEFAULT NULL,
  domain_dns_failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
  domain_checked_at TIMESTAMP NULL DEFAULT NULL,

  -- Source: a repository the GitHub App is installed on. Builds read it with an
  -- installation token; the App's webhook delivers its pushes.
  source_url VARCHAR(500) NULL,
  source_branch VARCHAR(100) DEFAULT 'main',
  source_provider VARCHAR(100) NULL,
  auto_deploy TINYINT(1) NOT NULL DEFAULT 1,
  reconciled_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last deploy reconcile check',

  -- From the new-app wizard (Setup_sql), encrypted with APP_KEY, run by the
  -- first deploy and then cleared; when it failed, the statements from the
  -- failed one on
  setup_sql LONGTEXT NULL,
  -- NULL when the wizard had none
  -- dismissed: failed, then dismissed from the app page (its SQL cleared)
  setup_sql_status ENUM('pending', 'done', 'failed', 'dismissed') NULL,
  -- Why it stopped; it is not run again
  setup_sql_error TEXT NULL,
  database_server_id INT NULL,
  current_deployment_id INT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,

  UNIQUE KEY unique_slug_environment (slug, environment),
  UNIQUE KEY unique_verified_domain (verified_domain),
  KEY idx_project_deleted (project_id, deleted_at),
  KEY idx_production_app (production_app_id),
  CONSTRAINT fk_app_project FOREIGN KEY (project_id) REFERENCES project (id),
  CONSTRAINT fk_app_production_app FOREIGN KEY (production_app_id) REFERENCES app (id),
  CONSTRAINT fk_app_database_server FOREIGN KEY (database_server_id) REFERENCES database_server (id)
);

-- How an app is built and run, one row per app (created with it; an app
-- without one gets the defaults). Changes take effect on the next deploy.
CREATE TABLE app_runtime (
  app_id INT PRIMARY KEY,
  -- Image build: extra extensions (lowercase names, JSON list) and shell
  -- lines run after composer (Build_commands)
  php_extensions JSON NOT NULL DEFAULT '[]',
  build_commands TEXT NOT NULL DEFAULT '',
  -- Directories kept across deploys (Manifests::persistent_paths), JSON list
  persistent_paths JSON NOT NULL DEFAULT '[]',
  -- Never sleeps when idle
  always_on TINYINT(1) NOT NULL DEFAULT 0,
  -- Trongate's ENV constant, patched into config/config.php (Trongate_config::env)
  trongate_env VARCHAR(20) NOT NULL DEFAULT 'dev',
  -- Mail from config/trongate_email.php goes to the platform's inbox (Mail_capture)
  mail_capture TINYINT(1) NOT NULL DEFAULT 1,
  -- Requested by the rollout gate and smoke test; '' is the home page (Manifests::health_path)
  health_path VARCHAR(200) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_runtime_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE
);

-- Messages the inbox captured (Mail_store), kept per Mail_capture's limits
CREATE TABLE app_mail (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  -- The SMTP envelope
  mail_from VARCHAR(320) NOT NULL,
  recipients VARCHAR(2000) NOT NULL,
  -- From and Subject headers, decoded, for the list
  from_header VARCHAR(500) NOT NULL DEFAULT '',
  subject VARCHAR(500) NOT NULL DEFAULT '',
  bytes INT NOT NULL,
  raw MEDIUMBLOB NOT NULL COMMENT 'the message as sent',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_app (app_id, id),
  KEY idx_created (created_at)
);

-- Log lines per app per 5-minute window (UTC), by level: sampled from running apps'
-- logs by the worker's sample_app_logs task (App_log_rates), kept 7 days.
CREATE TABLE app_log_rate (
  app_id INT NOT NULL,
  bucket DATETIME NOT NULL,
  total INT UNSIGNED NOT NULL DEFAULT 0,
  warnings INT UNSIGNED NOT NULL DEFAULT 0,
  errors INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (app_id, bucket),
  KEY idx_bucket (bucket)
);

-- Where sample_app_logs left off in each app's log: the last sampled line's timestamp
CREATE TABLE app_log_cursor (
  app_id INT PRIMARY KEY,
  cursor_time VARCHAR(30) NOT NULL
);

-- Trongate upgrades (modules/platform/tasks/upgrade_app.php): each run finds
-- the release an app is on and opens a pull request moving it to the newest.
CREATE TABLE app_upgrade (
  id INT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  -- Who asked; NULL for admin (MCP)
  customer_id INT NULL,
  -- opened: a pull request is open; current: already on the newest release
  status ENUM('pending', 'running', 'opened', 'current', 'failed') NOT NULL DEFAULT 'pending',
  from_version VARCHAR(20) NULL,
  to_version VARCHAR(20) NULL,
  pr_url VARCHAR(500) NULL,
  -- Upgrade_plan::counts() as JSON
  summary TEXT NULL,
  error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_app (app_id, id),
  CONSTRAINT fk_app_upgrade_app FOREIGN KEY (app_id) REFERENCES app (id),
  CONSTRAINT fk_app_upgrade_customer FOREIGN KEY (customer_id) REFERENCES customer (id)
);

-- customer_id is who pressed Deploy; NULL for webhook, reconcile and admin (MCP) deploys
CREATE TABLE deployment (
  id INT PRIMARY KEY AUTO_INCREMENT,
  project_id INT NOT NULL,
  app_id INT NOT NULL,
  customer_id INT NULL,
  status ENUM('pending', 'building', 'deploying', 'success', 'failed', 'rolled_back') DEFAULT 'pending',
  source_url VARCHAR(512) NULL,
  commit_sha VARCHAR(40) NULL,
  image VARCHAR(255) NULL,
  -- The repository's config/*.php at commit_sha (JSON: file name => source); the worker edits them at every apply
  config_files MEDIUMTEXT NULL,
  -- Why it failed and how to recover (JSON, Config_error::to_array); shown on the deployment page
  failure TEXT NULL,
  -- Trongate_config::CONFIG_VERSION it was last applied with; NULL before that was recorded
  config_version INT NULL,
  -- platform: redeploy_outdated_apps, the same commit again after the platform's app config changed
  trigger_type ENUM('manual', 'webhook', 'mcp', 'rollback', 'reconcile', 'platform') NOT NULL DEFAULT 'manual',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_app_status (app_id, status),
  CONSTRAINT fk_deployment_project FOREIGN KEY (project_id) REFERENCES project (id),
  CONSTRAINT fk_deployment_app FOREIGN KEY (app_id) REFERENCES app (id),
  CONSTRAINT fk_deployment_customer FOREIGN KEY (customer_id) REFERENCES customer (id)
);

-- An app's processes (Manifests::_process_deployment): other entrypoints run
-- from the app's own image, settings and database, e.g. a queue worker. Each
-- runs as a p{app id}-{name} Deployment that never sleeps, with this many replicas.
CREATE TABLE app_process (
  id INT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  -- Lowercase slug, not 'app' (p{id}-app is the app itself)
  name VARCHAR(20) NOT NULL,
  command VARCHAR(500) NOT NULL,
  replicas TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_app_process_name (app_id, name),
  CONSTRAINT fk_app_process_app FOREIGN KEY (app_id) REFERENCES app (id) ON DELETE CASCADE
);

-- A project's services (Project_service): an image that runs next to the
-- project's apps, always on, reachable by them at {NAME}_URL. Environment
-- variables live in secrets as (module 'service', id, service 'env').
CREATE TABLE service (
  id INT PRIMARY KEY AUTO_INCREMENT,
  project_id INT NOT NULL,
  -- Lowercase slug; the apps get {NAME}_URL
  name VARCHAR(40) NOT NULL,
  image VARCHAR(500) NOT NULL,
  port SMALLINT UNSIGNED NOT NULL,
  -- The container's memory request and limit
  memory_mb INT UNSIGNED NOT NULL,
  deployed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  -- NULL once deleted, so a live name is unique and a deleted one can be reused
  live_name VARCHAR(40) AS (IF(deleted_at IS NULL, name, NULL)) VIRTUAL,
  UNIQUE KEY unique_project_live_name (project_id, live_name),
  CONSTRAINT fk_service_project FOREIGN KEY (project_id) REFERENCES project (id)
);

ALTER TABLE app
  ADD CONSTRAINT fk_app_current_deployment FOREIGN KEY (current_deployment_id) REFERENCES deployment (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Worker (modules/platform/worker)
-- ---------------------------------------------------------------------------

-- Runs modules/platform/tasks/{script}.php. project_id NULL marks a platform-wide periodic task.
CREATE TABLE tasks (
  id INT PRIMARY KEY AUTO_INCREMENT,
  project_id INT NULL,
  app_id INT NULL,
  deployment_id INT NULL,
  script VARCHAR(100) NOT NULL,
  payload JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  started_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  failed_at TIMESTAMP NULL,
  error_message TEXT NULL,
  KEY idx_pending (started_at, completed_at, failed_at),
  CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES project (id),
  CONSTRAINT fk_tasks_app FOREIGN KEY (app_id) REFERENCES app (id),
  CONSTRAINT fk_tasks_deployment FOREIGN KEY (deployment_id) REFERENCES deployment (id)
);

CREATE TABLE task_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  task_id INT NOT NULL,
  level ENUM('info', 'warn', 'error') DEFAULT 'info',
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task_id (task_id, id),
  CONSTRAINT fk_task_logs_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE
);

-- Last run of each periodic task (Runtime::PERIODIC_TASKS)
CREATE TABLE scheduled_jobs (
  script VARCHAR(100) PRIMARY KEY,
  last_queued_at TIMESTAMP NULL,
  last_finished_at TIMESTAMP NULL,
  last_failed_at TIMESTAMP NULL,
  last_summary JSON NULL
);

-- ---------------------------------------------------------------------------
-- AI assistants (MCP): OAuth 2.1 clients, a customer's grants and their tokens
-- ---------------------------------------------------------------------------

-- Registered by the assistant itself (dynamic client registration, RFC 7591).
-- provider: which Mcp_provider accepted its redirect URIs (modules/mcp/providers).
CREATE TABLE mcp_oauth_client (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id VARCHAR(64) NOT NULL,
  client_secret_hash CHAR(64) NULL COMMENT 'sha256; NULL for public clients (PKCE only)',
  provider VARCHAR(40) NOT NULL,
  client_name VARCHAR(200) NOT NULL,
  redirect_uris JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_client_id (client_id),
  KEY idx_created (created_at)
);

-- A customer allowed a client to act for them: one connection on the Claude page.
-- Revoking it ends every token at once.
CREATE TABLE mcp_oauth_grant (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id INT NOT NULL,
  customer_id INT NOT NULL,
  scope VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL DEFAULT NULL,
  revoked_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_customer (customer_id, revoked_at),
  CONSTRAINT fk_mcp_grant_client FOREIGN KEY (client_id) REFERENCES mcp_oauth_client (id) ON DELETE CASCADE,
  CONSTRAINT fk_mcp_grant_customer FOREIGN KEY (customer_id) REFERENCES customer (id)
);

-- Authorization codes, access and refresh tokens, stored as sha256 only.
-- used_at: a code or refresh token is spent; spending one twice revokes its grant.
CREATE TABLE mcp_oauth_token (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  grant_id INT NOT NULL,
  kind ENUM('code', 'access', 'refresh') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  code_challenge VARCHAR(128) NULL,
  redirect_uri VARCHAR(500) NULL,
  expires_at DATETIME NOT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_token_hash (token_hash),
  KEY idx_expires (expires_at),
  CONSTRAINT fk_mcp_token_grant FOREIGN KEY (grant_id) REFERENCES mcp_oauth_grant (id) ON DELETE CASCADE
);

-- Migrations (db/migrations) already contained in this file; the worker
-- applies the rest on startup (Migrations::apply)
CREATE TABLE schema_migrations (
  name VARCHAR(190) PRIMARY KEY,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO schema_migrations (name) VALUES
  ('0001_service'),
  ('0002_deployment_config_files'),
  ('0003_app_upgrade'),
  ('0004_app_mail'),
  ('0005_deployment_config_version'),
  ('0006_app_process'),
  ('0007_drop_php_session'),
  ('0008_app_health_path'),
  ('0009_app_environments'),
  ('0010_app_setup_sql'),
  ('0011_app_setup_sql_dismissed'),
  ('0012_app_sql_import'),
  ('0013_app_build_commands'),
  ('0014_app_runtime');
