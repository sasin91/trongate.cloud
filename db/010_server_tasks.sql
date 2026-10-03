-- Trongate Cloud: Server task queue
-- Migration 010

CREATE TABLE IF NOT EXISTS server_tasks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    server_id INT NOT NULL,
    deployment_id INT NULL,
    script VARCHAR(255) NOT NULL,
    payload JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    error_message TEXT,
    error_code INT,
    error_trace TEXT,
    INDEX idx_pending (started_at, completed_at, failed_at),
    FOREIGN KEY (server_id) REFERENCES server(id),
    FOREIGN KEY (deployment_id) REFERENCES deployment(id)
);