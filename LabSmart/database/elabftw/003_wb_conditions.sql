-- Explicit additive migration. Antibody manuals and methods are immutable revisions.
CREATE TABLE IF NOT EXISTS smartlab_antibody_wb_profiles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    fields JSON NOT NULL,
    source_name VARCHAR(255) NOT NULL DEFAULT '',
    source_mime VARCHAR(100) NOT NULL DEFAULT '',
    source_hash CHAR(64) NOT NULL DEFAULT '',
    source_bytes MEDIUMBLOB NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wb_profile_revision (material_id, revision),
    KEY idx_wb_profile_team (team_id, material_id),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (material_id) REFERENCES smartlab_materials(id),
    FOREIGN KEY (created_by) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS smartlab_wb_methods (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    code VARCHAR(100) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    fields JSON NOT NULL,
    is_default TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wb_method_revision (team_id, code, revision),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (created_by) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO smartlab_schema_migrations (version) VALUES ('003_wb_conditions');
