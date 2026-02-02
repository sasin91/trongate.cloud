-- Trongate Cloud: Generic secrets storage
-- Migration 008

CREATE TABLE IF NOT EXISTS secrets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  module VARCHAR(50) NOT NULL, -- 'server', 'project', 'deployment', etc.
  module_id INT NOT NULL,
  encryption_key VARCHAR(255) NOT NULL, -- Base64 encoded libsodium key
  variables TEXT NOT NULL, -- Encrypted JSON with team DEK
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_module (module, module_id)
);
