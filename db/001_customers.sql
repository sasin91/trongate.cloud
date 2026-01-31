-- Trongate Cloud: Customer tables
-- Migration 001

CREATE TABLE IF NOT EXISTS customer (
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customer_account (
  id INT PRIMARY KEY AUTO_INCREMENT,
  customer_id INT NOT NULL,
  authenticator ENUM('password') DEFAULT 'password',
  status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
  used_at TIMESTAMP NULL,
  metadata JSON,
  FOREIGN KEY (customer_id) REFERENCES customer(id)
);

CREATE TABLE IF NOT EXISTS customer_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  customer_id INT NOT NULL,
  customer_account_id INT NULL,
  subject VARCHAR(255) NOT NULL,
  description TEXT,
  user_agent VARCHAR(512),
  ip VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customer(id),
  FOREIGN KEY (customer_account_id) REFERENCES customer_account(id)
);
