-- V3 Agent 收件箱基础表。
-- 应用运行时会自动创建（SmartlabDataCenter 构造器，CREATE TABLE IF NOT EXISTS），
-- 这里显式建表，保证 002_v3_ingest_safety.sql 的外键在全新数据库上可用。
CREATE TABLE IF NOT EXISTS smartlab_data_packages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id INT UNSIGNED NOT NULL,
    source_label VARCHAR(255) NOT NULL DEFAULT "",
    package_label VARCHAR(255) NOT NULL DEFAULT "",
    files_json JSON NULL,
    detected_at DATETIME NULL,
    status VARCHAR(24) NOT NULL DEFAULT "pending",
    experiment_id INT UNSIGNED NULL,
    linked_by INT UNSIGNED NULL,
    linked_at DATETIME NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_smartlab_data_packages_team_status (team_id, status),
    CONSTRAINT fk_smartlab_data_packages_team FOREIGN KEY (team_id) REFERENCES teams(id),
    CONSTRAINT fk_smartlab_data_packages_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE SET NULL,
    CONSTRAINT fk_smartlab_data_packages_linked_by FOREIGN KEY (linked_by) REFERENCES users(userid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_agents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT "",
    token VARCHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT "active",
    last_seen_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_smartlab_agents_token (token),
    CONSTRAINT fk_smartlab_agents_team FOREIGN KEY (team_id) REFERENCES teams(id),
    CONSTRAINT fk_smartlab_agents_created_by FOREIGN KEY (created_by) REFERENCES users(userid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
