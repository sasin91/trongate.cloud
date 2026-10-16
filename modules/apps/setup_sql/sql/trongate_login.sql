-- Login module: rate limiting and password resets

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
