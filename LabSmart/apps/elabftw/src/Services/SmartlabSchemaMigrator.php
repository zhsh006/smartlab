<?php

declare(strict_types=1);

namespace Elabftw\Services;

use Elabftw\Elabftw\Db;
use PDO;

/**
 * Centralized schema migration for SmartLab custom tables.
 *
 * Replaces the ad-hoc CREATE TABLE IF NOT EXISTS / ALTER TABLE calls
 * that were scattered across SmartLab model constructors.
 * Uses a static registry to ensure each migration runs once per request.
 */
final class SmartlabSchemaMigrator
{
    private Db $Db;

    /** @var array<string, true> */
    private static array $ensured = [];

    public function __construct()
    {
        $this->Db = Db::getConnection();
    }

    /**
     * Ensure a table exists. Idempotent within a single request.
     */
    public function ensureTable(string $tableName, string $createDdl): void
    {
        $key = 'table:' . $tableName;
        if (isset(self::$ensured[$key])) {
            return;
        }
        $this->Db->execute($this->Db->prepare($createDdl));
        self::$ensured[$key] = true;
    }

    /**
     * Ensure a column exists on a table. Idempotent within a single request.
     */
    public function ensureColumn(string $tableName, string $columnName, string $alterDdl): void
    {
        $key = 'column:' . $tableName . '.' . $columnName;
        if (isset(self::$ensured[$key])) {
            return;
        }

        $sql = 'SELECT COUNT(*) AS column_count
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
              AND COLUMN_NAME = :column';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':table', $tableName);
        $req->bindValue(':column', $columnName);
        $this->Db->execute($req);
        $row = $req->fetch(PDO::FETCH_ASSOC);
        if ((int) ($row['column_count'] ?? 0) > 0) {
            self::$ensured[$key] = true;
            return;
        }

        $this->Db->execute($this->Db->prepare($alterDdl));
        self::$ensured[$key] = true;
    }

    /**
     * Run all SmartLab schema migrations. Call once per request from
     * a bootstrap or controller entry point.
     */
    public static function run(): void
    {
        $migrator = new self();

        // smartlab_experiment_report_materials
        $migrator->ensureTable('smartlab_experiment_report_materials', 'CREATE TABLE IF NOT EXISTS smartlab_experiment_report_materials (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id INT UNSIGNED NOT NULL,
            project_id INT UNSIGNED NOT NULL,
            experiment_id INT UNSIGNED NOT NULL,
            review_flow_id INT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            student_summary TEXT NULL,
            discussion_points TEXT NULL,
            pdf_url VARCHAR(512) NOT NULL,
            key_upload_ids JSON NULL,
            status VARCHAR(64) NOT NULL DEFAULT "submitted",
            mentor_feedback_action VARCHAR(64) NULL,
            mentor_feedback TEXT NULL,
            feedback_by INT UNSIGNED NULL,
            feedback_at DATETIME NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_smartlab_exp_report_project_time (project_id, created_at),
            KEY idx_smartlab_exp_report_experiment_time (experiment_id, created_at),
            KEY idx_smartlab_exp_report_flow (review_flow_id),
            CONSTRAINT fk_smartlab_exp_report_team FOREIGN KEY (team_id) REFERENCES teams(id),
            CONSTRAINT fk_smartlab_exp_report_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_smartlab_exp_report_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE CASCADE,
            CONSTRAINT fk_smartlab_exp_report_flow FOREIGN KEY (review_flow_id) REFERENCES smartlab_review_flows(id) ON DELETE SET NULL,
            CONSTRAINT fk_smartlab_exp_report_created_by FOREIGN KEY (created_by) REFERENCES users(userid),
            CONSTRAINT fk_smartlab_exp_report_feedback_by FOREIGN KEY (feedback_by) REFERENCES users(userid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        // smartlab_experiment_usage_records
        $migrator->ensureTable('smartlab_experiment_usage_records', 'CREATE TABLE IF NOT EXISTS smartlab_experiment_usage_records (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            team_id INT UNSIGNED NOT NULL,
            experiment_id INT UNSIGNED NOT NULL,
            lot_id INT UNSIGNED NULL,
            usage_source VARCHAR(32) NOT NULL DEFAULT "manual",
            usage_type VARCHAR(64) NOT NULL DEFAULT "reagent",
            material_label VARCHAR(255) NOT NULL,
            material_brand VARCHAR(128) NULL,
            catalog_no VARCHAR(128) NULL,
            lot_no VARCHAR(128) NULL,
            location_label VARCHAR(255) NULL,
            quantity DECIMAL(12,4) NULL,
            unit VARCHAR(32) NULL,
            purpose TEXT NULL,
            note TEXT NULL,
            actor_userid INT UNSIGNED NOT NULL,
            occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX smartlab_usage_experiment_idx (experiment_id),
            INDEX smartlab_usage_team_idx (team_id),
            INDEX smartlab_usage_lot_idx (lot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        // smartlab_materials columns
        $materialColumns = [
            ['brand', 'ALTER TABLE smartlab_materials ADD COLUMN brand VARCHAR(128) NULL AFTER category'],
            ['manufacturer', 'ALTER TABLE smartlab_materials ADD COLUMN manufacturer VARCHAR(128) NULL AFTER brand'],
            ['catalog_no', 'ALTER TABLE smartlab_materials ADD COLUMN catalog_no VARCHAR(128) NULL AFTER manufacturer'],
            ['specification', 'ALTER TABLE smartlab_materials ADD COLUMN specification VARCHAR(128) NULL AFTER catalog_no'],
            ['package_size', 'ALTER TABLE smartlab_materials ADD COLUMN package_size VARCHAR(128) NULL AFTER specification'],
        ];
        foreach ($materialColumns as [$colName, $ddl]) {
            $migrator->ensureColumn('smartlab_materials', $colName, $ddl);
        }

        // smartlab_experiment_usage_records.purpose column
        $migrator->ensureColumn('smartlab_experiment_usage_records', 'purpose', 'ALTER TABLE smartlab_experiment_usage_records ADD COLUMN purpose TEXT NULL AFTER unit');
    }

    /**
     * Ensure the smartlab_materials extra columns exist (non-static version for Model use).
     */
    public function ensureMaterialColumns(): void
    {
        $columns = [
            ['brand', 'ALTER TABLE smartlab_materials ADD COLUMN brand VARCHAR(128) NULL AFTER category'],
            ['manufacturer', 'ALTER TABLE smartlab_materials ADD COLUMN manufacturer VARCHAR(128) NULL AFTER brand'],
            ['catalog_no', 'ALTER TABLE smartlab_materials ADD COLUMN catalog_no VARCHAR(128) NULL AFTER manufacturer'],
            ['specification', 'ALTER TABLE smartlab_materials ADD COLUMN specification VARCHAR(128) NULL AFTER catalog_no'],
            ['package_size', 'ALTER TABLE smartlab_materials ADD COLUMN package_size VARCHAR(128) NULL AFTER specification'],
        ];
        foreach ($columns as [$colName, $ddl]) {
            $this->ensureColumn('smartlab_materials', $colName, $ddl);
        }
    }

    public function ensureUsageRecordsTable(): void
    {
        $this->ensureTable('smartlab_experiment_usage_records', 'CREATE TABLE IF NOT EXISTS smartlab_experiment_usage_records (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            team_id INT UNSIGNED NOT NULL,
            experiment_id INT UNSIGNED NOT NULL,
            lot_id INT UNSIGNED NULL,
            usage_source VARCHAR(32) NOT NULL DEFAULT "manual",
            usage_type VARCHAR(64) NOT NULL DEFAULT "reagent",
            material_label VARCHAR(255) NOT NULL,
            material_brand VARCHAR(128) NULL,
            catalog_no VARCHAR(128) NULL,
            lot_no VARCHAR(128) NULL,
            location_label VARCHAR(255) NULL,
            quantity DECIMAL(12,4) NULL,
            unit VARCHAR(32) NULL,
            purpose TEXT NULL,
            note TEXT NULL,
            actor_userid INT UNSIGNED NOT NULL,
            occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX smartlab_usage_experiment_idx (experiment_id),
            INDEX smartlab_usage_team_idx (team_id),
            INDEX smartlab_usage_lot_idx (lot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->ensureColumn('smartlab_experiment_usage_records', 'purpose', 'ALTER TABLE smartlab_experiment_usage_records ADD COLUMN purpose TEXT NULL AFTER unit');
    }

    public function ensureReportMaterialTable(): void
    {
        $this->ensureTable('smartlab_experiment_report_materials', 'CREATE TABLE IF NOT EXISTS smartlab_experiment_report_materials (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id INT UNSIGNED NOT NULL,
            project_id INT UNSIGNED NOT NULL,
            experiment_id INT UNSIGNED NOT NULL,
            review_flow_id INT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            student_summary TEXT NULL,
            discussion_points TEXT NULL,
            pdf_url VARCHAR(512) NOT NULL,
            key_upload_ids JSON NULL,
            status VARCHAR(64) NOT NULL DEFAULT "submitted",
            mentor_feedback_action VARCHAR(64) NULL,
            mentor_feedback TEXT NULL,
            feedback_by INT UNSIGNED NULL,
            feedback_at DATETIME NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_smartlab_exp_report_project_time (project_id, created_at),
            KEY idx_smartlab_exp_report_experiment_time (experiment_id, created_at),
            KEY idx_smartlab_exp_report_flow (review_flow_id),
            CONSTRAINT fk_smartlab_exp_report_team FOREIGN KEY (team_id) REFERENCES teams(id),
            CONSTRAINT fk_smartlab_exp_report_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_smartlab_exp_report_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE CASCADE,
            CONSTRAINT fk_smartlab_exp_report_flow FOREIGN KEY (review_flow_id) REFERENCES smartlab_review_flows(id) ON DELETE SET NULL,
            CONSTRAINT fk_smartlab_exp_report_created_by FOREIGN KEY (created_by) REFERENCES users(userid),
            CONSTRAINT fk_smartlab_exp_report_feedback_by FOREIGN KEY (feedback_by) REFERENCES users(userid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    public function ensureProjectWorkTables(): void
    {
        $this->ensureTable('smartlab_project_tasks', 'CREATE TABLE IF NOT EXISTS smartlab_project_tasks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "todo",
            priority VARCHAR(32) NOT NULL DEFAULT "normal",
            due_date DATE NULL,
            assigned_userid INT UNSIGNED NOT NULL,
            created_by INT UNSIGNED NOT NULL,
            completed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_smartlab_project_tasks_project_status (project_id, status),
            CONSTRAINT fk_smartlab_project_tasks_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_smartlab_project_tasks_assignee FOREIGN KEY (assigned_userid) REFERENCES users(userid) ON DELETE CASCADE,
            CONSTRAINT fk_smartlab_project_tasks_created_by FOREIGN KEY (created_by) REFERENCES users(userid) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        $this->ensureTable('smartlab_project_reports', 'CREATE TABLE IF NOT EXISTS smartlab_project_reports (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id INT UNSIGNED NOT NULL,
            report_type VARCHAR(32) NOT NULL DEFAULT "weekly",
            title VARCHAR(255) NOT NULL,
            progress_summary TEXT NOT NULL,
            blockers TEXT NULL,
            next_plan TEXT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_smartlab_project_reports_project_time (project_id, created_at),
            CONSTRAINT fk_smartlab_project_reports_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_smartlab_project_reports_created_by FOREIGN KEY (created_by) REFERENCES users(userid) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        $this->ensureTable('smartlab_project_deliverables', 'CREATE TABLE IF NOT EXISTS smartlab_project_deliverables (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id INT UNSIGNED NOT NULL,
            deliverable_type VARCHAR(64) NOT NULL DEFAULT "data",
            title VARCHAR(255) NOT NULL,
            link_url VARCHAR(512) NULL,
            note TEXT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_smartlab_project_deliverables_project_time (project_id, created_at),
            CONSTRAINT fk_smartlab_project_deliverables_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_smartlab_project_deliverables_created_by FOREIGN KEY (created_by) REFERENCES users(userid) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        $this->ensureReportMaterialTable();
    }
}
