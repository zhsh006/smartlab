-- SmartLab V3 scan-to-login (QR) challenges for desktop/agent/web/mobile clients.
CREATE TABLE IF NOT EXISTS smartlab_qr_logins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  challenge CHAR(40) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  purpose VARCHAR(16) NOT NULL DEFAULT 'web',
  user_id INT UNSIGNED NULL,
  team_id INT UNSIGNED NULL,
  device_label VARCHAR(255) NOT NULL DEFAULT '',
  token CHAR(48) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  confirmed_at DATETIME NULL,
  consumed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qr_challenge (challenge),
  KEY idx_qr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
