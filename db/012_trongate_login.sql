-- Trongate Cloud: Upstream login module tables (ported from modules/setup/sql/setup.sql)
-- Migration 012

ALTER TABLE `trongate_administrators`
ADD COLUMN `email` varchar(255) DEFAULT NULL AFTER `username`;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_table` varchar(255) NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `attempted_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `target_table` (`target_table`(191)),
  KEY `identifier` (`identifier`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_table` varchar(255) NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expiry_date` int(11) NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `token` (`token`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;