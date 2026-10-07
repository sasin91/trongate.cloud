-- Trongate Cloud database schema.
--
-- The whole schema in one file, applied once to an empty database:
--   bin/import-db.sh            (local dev; drops and recreates tcloud first)
--   deploy/k8s/README.md        (cluster; the operator creates tcloud)
-- Change it in place while there are no customers to migrate; once there are,
-- add numbered migrations next to it.
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

-- PHP sessions, shared by every app replica (engine/Db_session_handler.php)
CREATE TABLE php_session (
  id VARCHAR(128) PRIMARY KEY,
  data MEDIUMBLOB NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  KEY idx_updated_at (updated_at)
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
  action ENUM('query', 'insert', 'update', 'delete', 'restore') NOT NULL,
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

-- Deleting an app soft-deletes the row: it keeps the slug ({slug}.PLATFORM_APPS_DOMAIN
-- is never reused), the deployment history and the database placement.
CREATE TABLE app (
  id INT PRIMARY KEY AUTO_INCREMENT,
  project_id INT NOT NULL,
  label VARCHAR(255) NOT NULL,
  slug VARCHAR(40) NOT NULL,
  type ENUM('trongate_v1', 'trongate_v2') NOT NULL,

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

  -- Runtime settings
  persistent_paths JSON DEFAULT '[]',
  php_extensions VARCHAR(255) NOT NULL DEFAULT '',
  always_on TINYINT(1) NOT NULL DEFAULT 0,
  -- Trongate's ENV constant, patched into config/config.php (Trongate_config::env)
  trongate_env VARCHAR(20) NOT NULL DEFAULT 'dev',
  database_server_id INT NULL,
  current_deployment_id INT NULL,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,

  UNIQUE KEY unique_slug (slug),
  UNIQUE KEY unique_verified_domain (verified_domain),
  KEY idx_project_deleted (project_id, deleted_at),
  CONSTRAINT fk_app_project FOREIGN KEY (project_id) REFERENCES project (id),
  CONSTRAINT fk_app_database_server FOREIGN KEY (database_server_id) REFERENCES database_server (id)
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
  trigger_type ENUM('manual', 'webhook', 'mcp', 'rollback', 'reconcile') NOT NULL DEFAULT 'manual',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_app_status (app_id, status),
  CONSTRAINT fk_deployment_project FOREIGN KEY (project_id) REFERENCES project (id),
  CONSTRAINT fk_deployment_app FOREIGN KEY (app_id) REFERENCES app (id),
  CONSTRAINT fk_deployment_customer FOREIGN KEY (customer_id) REFERENCES customer (id)
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
