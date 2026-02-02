-- Trongate Cloud: Customer tables
-- Migration 001

CREATE TABLE customer (
  id INT PRIMARY KEY AUTO_INCREMENT,
  trongate_user_id INT NOT NULL,
  status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
  used_at TIMESTAMP NULL,
  metadata JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (trongate_user_id) REFERENCES trongate_users(id)
);

INSERT INTO `trongate_user_levels` (`id`, `level_title`) VALUES
(2, 'customer');
