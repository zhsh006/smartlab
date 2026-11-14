-- SmartLab V3 "我的空间" (personal drive): app login tokens + per-user folders/files.
-- Idempotent; also created at runtime by SmartlabDrive::ensureSchema().

CREATE TABLE IF NOT EXISTS smartlab_app_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT "",
  token_hash CHAR(64) NOT NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_app_tokens_hash (token_hash),
  KEY idx_smartlab_app_tokens_user (user_id),
  CONSTRAINT fk_smartlab_app_tokens_user FOREIGN KEY (user_id) REFERENCES users(userid) ON DELETE CASCADE,
  CONSTRAINT fk_smartlab_app_tokens_team FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_user_folders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  path VARCHAR(700) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_user_folders (user_id, path),
  CONSTRAINT fk_smartlab_user_folders_user FOREIGN KEY (user_id) REFERENCES users(userid) ON DELETE CASCADE,
  CONSTRAINT fk_smartlab_user_folders_team FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_user_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  path VARCHAR(700) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  long_name VARCHAR(255) NOT NULL,
  storage TINYINT UNSIGNED NOT NULL DEFAULT 1,
  filesize BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL DEFAULT "",
  mime VARCHAR(255) NOT NULL DEFAULT "",
  source_device VARCHAR(255) NOT NULL DEFAULT "",
  source_path VARCHAR(700) NOT NULL DEFAULT "",
  source_modified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_user_files (user_id, path),
  CONSTRAINT fk_smartlab_user_files_user FOREIGN KEY (user_id) REFERENCES users(userid) ON DELETE CASCADE,
  CONSTRAINT fk_smartlab_user_files_team FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
