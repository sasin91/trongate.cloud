-- Trongate's default tables, offered (ticked) by the new-app wizard's
-- Database question when the repository has no setup.sql of its own
-- (Setup_sql_plan::offers_trongate_tables). They run before the
-- repository's files, so a file that makes these tables itself wins.

-- ---------------------------------------------------------------------------
-- Trongate auth core
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `trongate_user_levels`;
CREATE TABLE `trongate_user_levels` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `level_title` VARCHAR(125) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id 1 must match the user_levels key in config/login.php
INSERT INTO `trongate_user_levels` (`id`, `level_title`) VALUES
  (1, 'admin');

DROP TABLE IF EXISTS `trongate_users`;
CREATE TABLE `trongate_users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL,
  `user_level_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_user_level_id` (`user_level_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `trongate_users` (`id`, `code`, `user_level_id`) VALUES
  (1, 'Tz8tehsWsTPUHEtzfbYjXzaKNqLmfAUz', 1);

DROP TABLE IF EXISTS `trongate_tokens`;
CREATE TABLE `trongate_tokens` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(125) NOT NULL,
  `user_id` INT(11) NOT NULL DEFAULT 0,
  `expiry_date` INT(11) NOT NULL,
  `code` VARCHAR(3) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expiry_date` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Administrators (login user level 1, see config/login.php)
-- config/login.php allows login by `username` OR `email`, so both must exist.
-- num_logins / last_login are optional columns Login_model updates if present.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `trongate_administrators`;
CREATE TABLE `trongate_administrators` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(65) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `trongate_user_id` INT(11) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_login_attempts` INT(11) NOT NULL DEFAULT 0,
  `last_failed_attempt` INT(11) NOT NULL DEFAULT 0,
  `login_blocked_until` INT(11) NOT NULL DEFAULT 0,
  `failed_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `num_logins` INT(11) NOT NULL DEFAULT 0,
  `last_login` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`),
  UNIQUE KEY `uq_trongate_user_id` (`trongate_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- password = 'admin' (bcrypt, cost 11)
INSERT INTO `trongate_administrators`
  (`id`, `username`, `email`, `password`, `trongate_user_id`, `active`) VALUES
  (1, 'admin', NULL, '$2y$11$.D8CLISJkdDzYS8qnS1Vlez/OI6UZMmsVbVjXLBKxjbX/LAFiwDhe', 1, 1);

-- ---------------------------------------------------------------------------
-- Login module: rate limiting + password resets
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `target_table` VARCHAR(64) NOT NULL,
  `identifier` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
  `attempted_at` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_identifier` (`target_table`, `identifier`, `attempted_at`),
  KEY `idx_ip` (`target_table`, `ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `target_table` VARCHAR(64) NOT NULL,
  `identifier` VARCHAR(255) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `expiry_date` INT(11) NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
