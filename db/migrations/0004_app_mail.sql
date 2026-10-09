-- Mail capture (modules/platform/mail): on by default, apps send what
-- config/trongate_email.php sends to the platform's inbox instead of the
-- internet, and it shows on the app's Mail tab.
ALTER TABLE app ADD COLUMN mail_capture TINYINT(1) NOT NULL DEFAULT 1 AFTER trongate_env;

-- Messages the inbox captured (Mail_store), kept per Mail_capture's limits
CREATE TABLE IF NOT EXISTS app_mail (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  app_id INT NOT NULL,
  -- The SMTP envelope
  mail_from VARCHAR(320) NOT NULL,
  recipients VARCHAR(2000) NOT NULL,
  -- From and Subject headers, decoded, for the list
  from_header VARCHAR(500) NOT NULL DEFAULT '',
  subject VARCHAR(500) NOT NULL DEFAULT '',
  bytes INT NOT NULL,
  raw MEDIUMBLOB NOT NULL COMMENT 'the message as sent',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_app (app_id, id),
  KEY idx_created (created_at)
);
