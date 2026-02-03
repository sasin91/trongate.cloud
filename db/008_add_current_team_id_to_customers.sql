-- Trongate Cloud: Add current_team_id to customer
-- Migration 008

ALTER TABLE customer 
ADD COLUMN current_team_id INT DEFAULT NULL;

ALTER TABLE customer
ADD CONSTRAINT fk_customer_current_team 
FOREIGN KEY (current_team_id) REFERENCES team(id) ON DELETE SET NULL;
