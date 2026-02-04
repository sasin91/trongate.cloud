-- Trongate Cloud: Server logs for real-time streaming
-- Migration 009

CREATE TABLE IF NOT EXISTS server_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  server_id INT NOT NULL,
  level ENUM('info','warn','error') DEFAULT 'info',
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (server_id) REFERENCES server(id)
);
