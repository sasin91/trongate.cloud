-- Trongate auth core: user levels, users and tokens

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
