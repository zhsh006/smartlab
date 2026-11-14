-- Explicit additive migration. Run before deploying report feedback; no data reset.
CREATE TABLE IF NOT EXISTS smartlab_period_reports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT '',
    findings TEXT NULL,
    problems TEXT NULL,
    next_plan TEXT NULL,
    figure_json JSON NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    submitted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_smartlab_period_reports_user (user_id, status),
    CONSTRAINT fk_smartlab_period_reports_team FOREIGN KEY (team_id) REFERENCES teams(id),
    CONSTRAINT fk_smartlab_period_reports_user FOREIGN KEY (user_id) REFERENCES users(userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS smartlab_period_report_feedback (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    author_userid INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_period_feedback_report (report_id, id),
    CONSTRAINT fk_period_feedback_report FOREIGN KEY (report_id) REFERENCES smartlab_period_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_period_feedback_author FOREIGN KEY (author_userid) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT IGNORE INTO smartlab_schema_migrations (version) VALUES ('004_period_report_feedback');
