-- Apply explicitly before deploying V3 ingest safety; existing RAW and reports are retained.
CREATE TABLE IF NOT EXISTS smartlab_agent_receipts (
    agent_id INT UNSIGNED NOT NULL,
    request_id CHAR(64) NOT NULL,
    manifest_hash CHAR(64) NOT NULL,
    package_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (agent_id, request_id),
    UNIQUE KEY uq_agent_receipt_package (package_id),
    FOREIGN KEY (agent_id) REFERENCES smartlab_agents(id),
    FOREIGN KEY (package_id) REFERENCES smartlab_data_packages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS smartlab_agent_recipients (
    agent_id INT UNSIGNED NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    FOREIGN KEY (agent_id) REFERENCES smartlab_agents(id),
    FOREIGN KEY (user_id) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS smartlab_data_package_readers (
    package_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (package_id, user_id),
    FOREIGN KEY (package_id) REFERENCES smartlab_data_packages(id),
    FOREIGN KEY (user_id) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Only a known device creator receives old pending packages. Never guess unknown ownership.
INSERT IGNORE INTO smartlab_data_package_readers (package_id, user_id)
SELECT p.id, a.created_by FROM smartlab_data_packages p
JOIN smartlab_agents a ON a.team_id = p.team_id AND a.name = p.source_label
WHERE p.status = 'pending' AND a.created_by IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM smartlab_agent_receipts receipt WHERE receipt.package_id = p.id)
  AND NOT EXISTS (SELECT 1 FROM smartlab_data_package_readers reader WHERE reader.package_id = p.id)
  AND 1 = (SELECT COUNT(*) FROM smartlab_agents same_name WHERE same_name.team_id = p.team_id AND same_name.name = p.source_label);
CREATE TABLE IF NOT EXISTS smartlab_schema_migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO smartlab_schema_migrations (version) VALUES ('002_v3_ingest_safety');
