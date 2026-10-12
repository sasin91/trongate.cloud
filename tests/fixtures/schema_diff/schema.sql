-- phpMyAdmin SQL Dump
/*!40101 SET NAMES utf8mb4 */;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE `trongate_users` (
  `id` int(11) NOT NULL,
  `code` varchar(32) DEFAULT NULL,
  `user_level_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `trongate_users` (`id`, `code`, `user_level_id`) VALUES (1, 'abc;def', 1);

CREATE TABLE `jobs` (
  `id` int(11) NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Shown; on the board',
  `salary` decimal(10, 2) NOT NULL DEFAULT '0.00',
  `status` enum('Open', 'Closed') NOT NULL DEFAULT 'Open',
  `remote` tinyint(1) NOT NULL DEFAULT 0,
  `posted_at` timestamp,
  `closes_at` timestamp,
  `meta` json DEFAULT NULL,
  `trongate_user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `trongate_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `title_prefix` (`title`(20)),
  ADD CONSTRAINT `fk_jobs_user` FOREIGN KEY (`trongate_user_id`) REFERENCES `trongate_users` (`id`) ON DELETE CASCADE;

ALTER TABLE `trongate_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

CREATE TABLE notes (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  job_id INT NOT NULL,
  body TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE scratch (id INT);
DROP TABLE IF EXISTS scratch;
