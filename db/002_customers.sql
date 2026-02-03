-- Trongate Cloud: Customer tables
-- Migration 001

CREATE TABLE customer (
  id INT PRIMARY KEY AUTO_INCREMENT,
  trongate_user_id INT NOT NULL,
  email varchar(65) DEFAULT NULL,
  password varchar(60) DEFAULT NULL,
  active tinyint(1) NOT NULL DEFAULT 0,
  failed_login_attempts int(11) NOT NULL DEFAULT 0,
  last_failed_attempt int(11) NOT NULL DEFAULT 0,
  login_blocked_until int(11) NOT NULL DEFAULT 0,
  failed_login_ip varchar(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (trongate_user_id) REFERENCES trongate_users(id),
  UNIQUE KEY unique_email (email)
);

INSERT INTO `trongate_user_levels` (`id`, `level_title`) VALUES
(2, 'customer');
