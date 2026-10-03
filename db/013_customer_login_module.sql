-- Trongate Cloud: Customer auth moved to the login module (user level 2)
-- Migration 013
-- Lockouts are now tracked in login_attempts (see 012_trongate_login.sql).

ALTER TABLE customer
  DROP COLUMN failed_login_attempts,
  DROP COLUMN last_failed_attempt,
  DROP COLUMN login_blocked_until,
  DROP COLUMN failed_login_ip;
