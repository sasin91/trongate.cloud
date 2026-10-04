-- Trongate Cloud: Projects hold apps; teams are gone
-- Migration 020
--
-- A project is one Kubernetes namespace with members (owner | member) and
-- holds any number of apps; an app is one repository deployed as a
-- Deployment + Service. What used to be a "project" row is now an "app" row,
-- and what used to be a "team" is now a "project". Ids are kept on both, so
-- webhook URLs (webhooks/github/{app id}), subdomains, the p{id}-* cluster
-- objects and the tc_p{id} databases keep working.
--
-- Existing projects keep their namespace "tc-team-{id}" (stored on the row),
-- so nothing moves in the cluster; new projects get "project-{id}".

-- ---------------------------------------------------------------------------
-- 1. project -> app
-- ---------------------------------------------------------------------------

RENAME TABLE project TO app;

ALTER TABLE deployment RENAME COLUMN project_id TO app_id;
ALTER TABLE tasks RENAME COLUMN project_id TO app_id;

UPDATE tasks SET script = 'destroy_app' WHERE script = 'destroy_project';

-- ---------------------------------------------------------------------------
-- 2. team -> project, team_member -> project_member
-- ---------------------------------------------------------------------------

RENAME TABLE team TO project, team_member TO project_member;

ALTER TABLE app
  RENAME COLUMN team_id TO project_id,
  RENAME INDEX idx_team_deleted TO idx_project_deleted;

ALTER TABLE project_member
  RENAME COLUMN team_id TO project_id,
  RENAME INDEX unique_team_customer TO unique_project_customer;

ALTER TABLE deployment
  RENAME COLUMN team_id TO project_id,
  RENAME COLUMN team_member_id TO project_member_id;

ALTER TABLE tasks RENAME COLUMN team_id TO project_id;

ALTER TABLE customer DROP FOREIGN KEY fk_customer_current_team;
ALTER TABLE customer DROP COLUMN current_team_id;

-- Two roles: the owner manages members and deletes; members see and deploy
UPDATE project_member SET role = 'member' WHERE role NOT IN ('owner', 'member');
ALTER TABLE project_member
  MODIFY role ENUM('owner', 'member') NOT NULL DEFAULT 'member';

-- The Kubernetes namespace, set once when the project is created
ALTER TABLE project
  ADD COLUMN namespace VARCHAR(63) NULL AFTER name,
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;

UPDATE project SET namespace = CONCAT('tc-team-', id);

ALTER TABLE project
  MODIFY namespace VARCHAR(63) NOT NULL,
  ADD UNIQUE KEY unique_namespace (namespace);

-- ---------------------------------------------------------------------------
-- 3. The GitHub token belongs to the customer who connected it
-- ---------------------------------------------------------------------------

UPDATE IGNORE secrets s
JOIN project p ON s.module = 'team' AND s.module_id = p.id
SET s.module = 'customer', s.module_id = p.owner_id;

-- An owner of several old teams keeps the token that moved first
DELETE FROM secrets WHERE module = 'team';
