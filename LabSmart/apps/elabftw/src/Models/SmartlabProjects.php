<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2026 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Exceptions\ResourceNotFoundException;
use Elabftw\Models\Users\Users;
use Elabftw\Services\SmartlabSchemaMigrator;
use PDO;

final class SmartlabProjects extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        (new SmartlabSchemaMigrator())->ensureProjectWorkTables();
    }

    private function ensureProjectWorkTables(): void
    {
        $this->Db->execute($this->Db->prepare('CREATE TABLE IF NOT EXISTS smartlab_project_tasks (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci'));

        $this->Db->execute($this->Db->prepare('CREATE TABLE IF NOT EXISTS smartlab_project_reports (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci'));

        $this->Db->execute($this->Db->prepare('CREATE TABLE IF NOT EXISTS smartlab_project_deliverables (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci'));

        $this->Db->execute($this->Db->prepare('CREATE TABLE IF NOT EXISTS smartlab_experiment_report_materials (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci'));
    }

    public function readAll(): array
    {
        $sql = 'SELECT
                p.id,
                p.code,
                p.name,
                p.description,
                p.status,
                p.created_at,
                COUNT(DISTINCT pm.user_id) AS member_count,
                COUNT(DISTINCT pe.id) AS entity_count
            FROM smartlab_projects AS p
            LEFT JOIN smartlab_project_members AS pm ON pm.project_id = p.id
            LEFT JOIN smartlab_project_entities AS pe ON pe.project_id = p.id
            WHERE p.team_id = :team
            GROUP BY p.id
            ORDER BY p.updated_at DESC, p.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readForCurrentUser(): array
    {
        $sql = 'SELECT
                p.id,
                p.code,
                p.name,
                p.description,
                p.status,
                p.created_at,
                pm.role,
                COUNT(DISTINCT pe.id) AS entity_count,
                COUNT(DISTINCT CASE WHEN pe.entity_type = "experiment" THEN pe.id END) AS experiment_count,
                COUNT(DISTINCT s.id) AS sample_count,
                COUNT(DISTINCT rf.id) AS review_count
            FROM smartlab_projects AS p
            INNER JOIN smartlab_project_members AS pm ON pm.project_id = p.id
            LEFT JOIN smartlab_project_entities AS pe ON pe.project_id = p.id
            LEFT JOIN smartlab_samples AS s ON s.project_id = p.id
            LEFT JOIN smartlab_review_flows AS rf ON rf.project_id = p.id
            WHERE p.team_id = :team
              AND pm.user_id = :userid
            GROUP BY p.id, pm.role
            ORDER BY p.updated_at DESC, p.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function createForCurrentUser(string $code, string $name, string $description): int
    {
        $sql = 'INSERT INTO smartlab_projects (team_id, code, name, description, status, created_by)
            VALUES (:team, :code, :name, :description, 1, :created_by)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':code', $code);
        $req->bindValue(':name', $name);
        $req->bindValue(':description', $description !== '' ? $description : null);
        $req->bindValue(':created_by', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);

        $projectId = $this->Db->lastInsertId();
        $sql = 'INSERT INTO smartlab_project_members (project_id, user_id, role)
            VALUES (:project_id, :userid, "负责人")';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);

        return $projectId;
    }

    public function createTaskForCurrentUser(int $projectId, string $title, string $description, ?string $dueDate, string $priority): void
    {
        $this->assertProjectForCurrentUser($projectId);
        $priority = in_array($priority, array('low', 'normal', 'high'), true) ? $priority : 'normal';
        $sql = 'INSERT INTO smartlab_project_tasks
            (project_id, title, description, priority, due_date, assigned_userid, created_by)
            VALUES (:project_id, :title, :description, :priority, :due_date, :assigned_userid, :created_by)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':title', $title);
        $req->bindValue(':description', $description !== '' ? $description : null);
        $req->bindValue(':priority', $priority);
        $req->bindValue(':due_date', $dueDate ?: null);
        $req->bindValue(':assigned_userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $req->bindValue(':created_by', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    public function updateTaskStatusForCurrentUser(int $projectId, int $taskId, string $status): void
    {
        $this->assertProjectForCurrentUser($projectId);
        $status = in_array($status, array('todo', 'doing', 'blocked', 'done'), true) ? $status : 'todo';
        $sql = 'UPDATE smartlab_project_tasks
            SET status = :status,
                completed_at = CASE WHEN :status_done = "done" THEN NOW() ELSE NULL END
            WHERE id = :task_id
              AND project_id = :project_id
              AND assigned_userid = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':status', $status);
        $req->bindValue(':status_done', $status);
        $req->bindValue(':task_id', $taskId, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    public function createReportForCurrentUser(int $projectId, string $reportType, string $title, string $progressSummary, string $blockers, string $nextPlan): void
    {
        $this->assertProjectForCurrentUser($projectId);
        $reportType = in_array($reportType, array('weekly', 'monthly', 'milestone'), true) ? $reportType : 'weekly';
        $sql = 'INSERT INTO smartlab_project_reports
            (project_id, report_type, title, progress_summary, blockers, next_plan, created_by)
            VALUES (:project_id, :report_type, :title, :progress_summary, :blockers, :next_plan, :created_by)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':report_type', $reportType);
        $req->bindValue(':title', $title);
        $req->bindValue(':progress_summary', $progressSummary);
        $req->bindValue(':blockers', $blockers !== '' ? $blockers : null);
        $req->bindValue(':next_plan', $nextPlan !== '' ? $nextPlan : null);
        $req->bindValue(':created_by', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    public function createDeliverableForCurrentUser(int $projectId, string $deliverableType, string $title, string $linkUrl, string $note): void
    {
        $this->assertProjectForCurrentUser($projectId);
        $deliverableType = in_array($deliverableType, array('data', 'figure', 'protocol', 'paper', 'meeting', 'other'), true) ? $deliverableType : 'data';
        $sql = 'INSERT INTO smartlab_project_deliverables
            (project_id, deliverable_type, title, link_url, note, created_by)
            VALUES (:project_id, :deliverable_type, :title, :link_url, :note, :created_by)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':deliverable_type', $deliverableType);
        $req->bindValue(':title', $title);
        $req->bindValue(':link_url', $linkUrl !== '' ? $linkUrl : null);
        $req->bindValue(':note', $note !== '' ? $note : null);
        $req->bindValue(':created_by', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function readOneForCurrentUser(int $projectId): array
    {
        $sql = 'SELECT
                p.id,
                p.code,
                p.name,
                p.description,
                p.status,
                p.created_at,
                p.updated_at,
                pm.role,
                CONCAT(creator.firstname, " ", creator.lastname) AS creator_name,
                COUNT(DISTINCT pe.id) AS entity_count,
                COUNT(DISTINCT CASE WHEN pe.entity_type = "experiment" THEN pe.id END) AS experiment_count,
                COUNT(DISTINCT CASE WHEN pe.entity_type = "item" THEN pe.id END) AS resource_count,
                COUNT(DISTINCT s.id) AS sample_count,
                COUNT(DISTINCT rf.id) AS review_count
            FROM smartlab_projects AS p
            INNER JOIN smartlab_project_members AS pm ON pm.project_id = p.id
            LEFT JOIN users AS creator ON creator.userid = p.created_by
            LEFT JOIN smartlab_project_entities AS pe ON pe.project_id = p.id
            LEFT JOIN smartlab_samples AS s ON s.project_id = p.id
            LEFT JOIN smartlab_review_flows AS rf ON rf.project_id = p.id
            WHERE p.team_id = :team
              AND p.id = :project_id
              AND pm.user_id = :userid
            GROUP BY p.id, pm.role';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);

        return $this->Db->fetch($req);
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function readOne(int $projectId): array
    {
        $sql = 'SELECT
                p.id,
                p.code,
                p.name,
                p.description,
                p.status,
                p.created_at,
                p.updated_at,
                CONCAT(creator.firstname, " ", creator.lastname) AS creator_name,
                COUNT(DISTINCT pm.user_id) AS member_count,
                COUNT(DISTINCT pe.id) AS entity_count,
                COUNT(DISTINCT CASE WHEN pe.entity_type = "experiment" THEN pe.id END) AS experiment_count,
                COUNT(DISTINCT CASE WHEN pe.entity_type = "item" THEN pe.id END) AS resource_count,
                COUNT(DISTINCT s.id) AS sample_count,
                COUNT(DISTINCT rf.id) AS review_count
            FROM smartlab_projects AS p
            LEFT JOIN users AS creator ON creator.userid = p.created_by
            LEFT JOIN smartlab_project_members AS pm ON pm.project_id = p.id
            LEFT JOIN smartlab_project_entities AS pe ON pe.project_id = p.id
            LEFT JOIN smartlab_samples AS s ON s.project_id = p.id
            LEFT JOIN smartlab_review_flows AS rf ON rf.project_id = p.id
            WHERE p.team_id = :team AND p.id = :project_id
            GROUP BY p.id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $this->Db->fetch($req);
    }

    /**
     * Find a project by team and code. Returns the project ID or null.
     */
    public function findByCode(string $code): ?int
    {
        $sql = 'SELECT id FROM smartlab_projects WHERE team_id = :team AND code = :code LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':code', $code);
        $this->Db->execute($req);
        $id = $req->fetchColumn();
        if ($id === false) {
            return null;
        }
        return (int) $id;
    }

    public function readMembers(int $projectId): array
    {
        $sql = 'SELECT
                pm.role,
                pm.created_at,
                u.userid,
                u.firstname,
                u.lastname,
                u.email
            FROM smartlab_project_members AS pm
            INNER JOIN smartlab_projects AS p ON p.id = pm.project_id
            INNER JOIN users AS u ON u.userid = pm.user_id
            WHERE p.team_id = :team AND p.id = :project_id
            ORDER BY pm.role ASC, u.lastname ASC, u.firstname ASC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readSamples(int $projectId): array
    {
        $sql = 'SELECT
                s.id,
                s.code,
                s.name,
                s.sample_type,
                s.status,
                s.quantity,
                s.unit,
                i.id AS source_item_id,
                i.title AS source_item_title
            FROM smartlab_samples AS s
            LEFT JOIN items AS i ON i.id = s.source_item_id
            WHERE s.team_id = :team AND s.project_id = :project_id
            ORDER BY s.updated_at DESC, s.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readEntities(int $projectId): array
    {
        $sql = 'SELECT
                pe.id,
                pe.entity_type,
                pe.entity_id,
                pe.created_at,
                e.title AS experiment_title,
                i.title AS item_title,
                s.code AS sample_code,
                s.name AS sample_name
            FROM smartlab_project_entities AS pe
            INNER JOIN smartlab_projects AS p ON p.id = pe.project_id
            LEFT JOIN experiments AS e ON pe.entity_type = "experiment" AND e.id = pe.entity_id
            LEFT JOIN items AS i ON pe.entity_type = "item" AND i.id = pe.entity_id
            LEFT JOIN smartlab_samples AS s ON pe.entity_type = "sample" AND s.id = pe.entity_id
            WHERE p.team_id = :team AND p.id = :project_id
            ORDER BY pe.created_at DESC, pe.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readExperiments(int $projectId): array
    {
        $sql = 'SELECT
                e.id,
                e.title,
                e.date,
                e.modified_at,
                latest_flow.status AS review_status,
                latest_task.decision AS review_decision,
                latest_task.comment AS review_comment
            FROM smartlab_project_entities AS pe
            INNER JOIN smartlab_projects AS p ON p.id = pe.project_id
            INNER JOIN experiments AS e
                ON pe.entity_type = "experiment"
               AND e.id = pe.entity_id
               AND e.team = p.team_id
            LEFT JOIN smartlab_review_flows AS latest_flow
                ON latest_flow.id = (
                    SELECT rf2.id
                    FROM smartlab_review_flows AS rf2
                    WHERE rf2.project_id = p.id
                      AND rf2.entity_type = "experiment"
                      AND rf2.entity_id = e.id
                    ORDER BY rf2.updated_at DESC, rf2.id DESC
                    LIMIT 1
                )
            LEFT JOIN smartlab_review_tasks AS latest_task ON latest_task.flow_id = latest_flow.id
            WHERE p.team_id = :team
              AND p.id = :project_id
            ORDER BY e.modified_at DESC, e.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readRecentActivity(int $projectId): array
    {
        $sql = '(
                SELECT
                    "sample_event" AS activity_type,
                    se.id AS activity_id,
                    se.occurred_at AS activity_at,
                    s.code AS subject_code,
                    s.name AS subject_name,
                    se.event_type AS state_label,
                    se.note AS detail,
                    CONCAT(actor.firstname, " ", actor.lastname) AS actor_name
                FROM smartlab_sample_events AS se
                INNER JOIN smartlab_samples AS s ON s.id = se.sample_id
                LEFT JOIN users AS actor ON actor.userid = se.actor_userid
                WHERE s.project_id = :project_id
                  AND s.team_id = :team
            )
            UNION ALL
            (
                SELECT
                    "review_flow" AS activity_type,
                    rf.id AS activity_id,
                    rf.updated_at AS activity_at,
                    p.code AS subject_code,
                    p.name AS subject_name,
                    rf.status AS state_label,
                    rt.comment AS detail,
                    CONCAT(assignee.firstname, " ", assignee.lastname) AS actor_name
                FROM smartlab_review_flows AS rf
                INNER JOIN smartlab_projects AS p ON p.id = rf.project_id
                LEFT JOIN smartlab_review_tasks AS rt ON rt.flow_id = rf.id
                LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
                WHERE p.id = :project_id
                  AND p.team_id = :team
            )
            UNION ALL
            (
                SELECT
                    "experiment_report" AS activity_type,
                    material.id AS activity_id,
                    material.updated_at AS activity_at,
                    p.code AS subject_code,
                    material.title AS subject_name,
                    material.status AS state_label,
                    COALESCE(material.mentor_feedback, material.student_summary) AS detail,
                    CONCAT(student.firstname, " ", student.lastname) AS actor_name
                FROM smartlab_experiment_report_materials AS material
                INNER JOIN smartlab_projects AS p ON p.id = material.project_id
                LEFT JOIN users AS student ON student.userid = material.created_by
                WHERE p.id = :project_id
                  AND p.team_id = :team
            )
            ORDER BY activity_at DESC, activity_id DESC
            LIMIT 8';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readReviewFlows(int $projectId): array
    {
        $sql = 'SELECT
                rf.id,
                rf.entity_type,
                rf.entity_id,
                rf.status,
                rf.updated_at,
                CONCAT(req.firstname, " ", req.lastname) AS requester_name,
                CONCAT(assignee.firstname, " ", assignee.lastname) AS assignee_name,
                rt.decision,
                rt.comment
            FROM smartlab_review_flows AS rf
            INNER JOIN smartlab_projects AS p ON p.id = rf.project_id
            LEFT JOIN users AS req ON req.userid = rf.requester_userid
            LEFT JOIN smartlab_review_tasks AS rt ON rt.flow_id = rf.id
            LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
            WHERE p.team_id = :team AND p.id = :project_id
            ORDER BY rf.updated_at DESC, rf.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readExperimentReportMaterials(int $projectId): array
    {
        $this->assertProjectInTeam($projectId);
        $sql = 'SELECT
                material.id,
                material.experiment_id,
                material.review_flow_id,
                material.title,
                material.student_summary,
                material.discussion_points,
                material.pdf_url,
                material.key_upload_ids,
                material.status,
                material.mentor_feedback_action,
                material.mentor_feedback,
                material.feedback_at,
                material.created_at,
                experiment.title AS experiment_title,
                experiment.date AS experiment_date,
                CONCAT(student.firstname, " ", student.lastname) AS student_name,
                CONCAT(feedback_user.firstname, " ", feedback_user.lastname) AS feedback_user_name
            FROM smartlab_experiment_report_materials AS material
            INNER JOIN smartlab_projects AS project ON project.id = material.project_id
            INNER JOIN experiments AS experiment ON experiment.id = material.experiment_id
            LEFT JOIN users AS student ON student.userid = material.created_by
            LEFT JOIN users AS feedback_user ON feedback_user.userid = material.feedback_by
            WHERE material.project_id = :project_id
              AND material.team_id = :team
              AND project.team_id = :team
            ORDER BY material.created_at DESC, material.id DESC
            LIMIT 50';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $materials = $req->fetchAll();

        $uploadsById = $this->readUploadsForMaterials($materials);
        foreach ($materials as &$material) {
            $uploadIds = json_decode($material['key_upload_ids'] ?? '[]', true);
            if (!is_array($uploadIds)) {
                $uploadIds = array();
            }
            $material['key_uploads'] = array_values(array_filter(
                array_map(static fn($uploadId): ?array => $uploadsById[(int) $uploadId] ?? null, $uploadIds),
            ));
        }
        unset($material);

        return $materials;
    }

    public function saveExperimentReportFeedback(int $projectId, int $materialId, string $action, string $feedback): void
    {
        $this->assertProjectInTeam($projectId);
        $actionMap = array(
            'continue' => array('status' => 'continue', 'flow' => 'approved', 'task' => 'approved'),
            'repeat_experiment' => array('status' => 'repeat_experiment', 'flow' => 'needs_revision', 'task' => 'changes_requested'),
            'insufficient_data' => array('status' => 'insufficient_data', 'flow' => 'needs_revision', 'task' => 'changes_requested'),
            'figure_ready' => array('status' => 'figure_ready', 'flow' => 'approved', 'task' => 'approved'),
            'manuscript_candidate' => array('status' => 'manuscript_candidate', 'flow' => 'approved', 'task' => 'approved'),
        );
        if (!isset($actionMap[$action])) {
            return;
        }

        $sql = 'SELECT id, review_flow_id
            FROM smartlab_experiment_report_materials
            WHERE id = :material_id
              AND project_id = :project_id
              AND team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':material_id', $materialId, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $material = $this->Db->fetch($req);

        $sql = 'UPDATE smartlab_experiment_report_materials
            SET status = :status,
                mentor_feedback_action = :feedback_action,
                mentor_feedback = :feedback,
                feedback_by = :feedback_by,
                feedback_at = NOW(),
                updated_at = NOW()
            WHERE id = :material_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':status', $actionMap[$action]['status']);
        $req->bindValue(':feedback_action', $action);
        $req->bindValue(':feedback', $feedback !== '' ? $feedback : null);
        $req->bindValue(':feedback_by', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':material_id', (int) $material['id'], PDO::PARAM_INT);
        $this->Db->execute($req);

        $flowId = (int) ($material['review_flow_id'] ?? 0);
        if ($flowId < 1) {
            return;
        }

        $sql = 'UPDATE smartlab_review_flows
            SET status = :status,
                updated_at = NOW()
            WHERE id = :flow_id
              AND project_id = :project_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':status', $actionMap[$action]['flow']);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);

        $sql = 'UPDATE smartlab_review_tasks
            SET decision = :decision,
                comment = :comment,
                decided_at = NOW()
            WHERE flow_id = :flow_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':decision', $actionMap[$action]['task']);
        $req->bindValue(':comment', $feedback !== '' ? $feedback : null);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    private function readUploadsForMaterials(array $materials): array
    {
        $uploadIds = array();
        foreach ($materials as $material) {
            $materialUploadIds = json_decode($material['key_upload_ids'] ?? '[]', true);
            if (!is_array($materialUploadIds)) {
                continue;
            }
            foreach ($materialUploadIds as $uploadId) {
                $uploadId = (int) $uploadId;
                if ($uploadId > 0) {
                    $uploadIds[] = $uploadId;
                }
            }
        }
        $uploadIds = array_values(array_unique($uploadIds));
        if (empty($uploadIds)) {
            return array();
        }

        $placeholders = implode(', ', array_fill(0, count($uploadIds), '?'));
        $sql = sprintf(
            'SELECT
                    upload.id,
                    upload.real_name,
                    upload.long_name,
                    upload.comment,
                    upload.storage,
                    upload.item_id
                FROM uploads AS upload
                INNER JOIN experiments AS experiment
                    ON experiment.id = upload.item_id
                   AND upload.type = "experiments"
                WHERE upload.id IN (%s)
                  AND experiment.team = ?',
            $placeholders,
        );
        $req = $this->Db->prepare($sql);
        foreach ($uploadIds as $index => $uploadId) {
            $req->bindValue($index + 1, $uploadId, PDO::PARAM_INT);
        }
        $req->bindValue(count($uploadIds) + 1, $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        $uploadsById = array();
        foreach ($req->fetchAll() as $upload) {
            $uploadsById[(int) $upload['id']] = $upload;
        }
        return $uploadsById;
    }

    public function readTasksForCurrentUser(int $projectId): array
    {
        $this->assertProjectForCurrentUser($projectId);
        $sql = 'SELECT
                t.id,
                t.title,
                t.description,
                t.status,
                t.priority,
                t.due_date,
                t.completed_at,
                t.created_at,
                t.updated_at,
                CONCAT(creator.firstname, " ", creator.lastname) AS creator_name
            FROM smartlab_project_tasks AS t
            LEFT JOIN users AS creator ON creator.userid = t.created_by
            WHERE t.project_id = :project_id
              AND t.assigned_userid = :userid
            ORDER BY
                CASE t.status
                    WHEN "doing" THEN 0
                    WHEN "blocked" THEN 1
                    WHEN "todo" THEN 2
                    ELSE 3
                END ASC,
                CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
                t.due_date ASC,
                t.updated_at DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readReports(int $projectId): array
    {
        $this->assertProjectForCurrentUser($projectId);
        $sql = 'SELECT
                r.id,
                r.report_type,
                r.title,
                r.progress_summary,
                r.blockers,
                r.next_plan,
                r.created_at,
                r.updated_at,
                CONCAT(creator.firstname, " ", creator.lastname) AS creator_name
            FROM smartlab_project_reports AS r
            LEFT JOIN users AS creator ON creator.userid = r.created_by
            WHERE r.project_id = :project_id
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT 20';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readDeliverables(int $projectId): array
    {
        $this->assertProjectForCurrentUser($projectId);
        $sql = 'SELECT
                d.id,
                d.deliverable_type,
                d.title,
                d.link_url,
                d.note,
                d.created_at,
                d.updated_at,
                CONCAT(creator.firstname, " ", creator.lastname) AS creator_name
            FROM smartlab_project_deliverables AS d
            LEFT JOIN users AS creator ON creator.userid = d.created_by
            WHERE d.project_id = :project_id
            ORDER BY d.created_at DESC, d.id DESC
            LIMIT 20';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readAvailableExperiments(int $projectId): array
    {
        $sql = 'SELECT
                e.id,
                e.title,
                e.modified_at
            FROM experiments AS e
            WHERE e.team = :team
              AND e.state = 1
              AND NOT EXISTS (
                SELECT 1
                FROM smartlab_project_entities AS pe
                WHERE pe.project_id = :project_id
                  AND pe.entity_type = "experiment"
                  AND pe.entity_id = e.id
              )
            ORDER BY e.modified_at DESC, e.id DESC
            LIMIT 50';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readAvailableItems(int $projectId): array
    {
        $sql = 'SELECT
                i.id,
                i.title,
                i.modified_at
            FROM items AS i
            WHERE i.team = :team
              AND i.state = 1
              AND NOT EXISTS (
                SELECT 1
                FROM smartlab_project_entities AS pe
                WHERE pe.project_id = :project_id
                  AND pe.entity_type = "item"
                  AND pe.entity_id = i.id
              )
            ORDER BY i.modified_at DESC, i.id DESC
            LIMIT 50';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function addEntity(int $projectId, string $entityType, int $entityId): void
    {
        if (!in_array($entityType, array('experiment', 'item'), true) || $entityId < 1) {
            return;
        }

        $this->assertProjectInTeam($projectId);

        $sql = 'INSERT IGNORE INTO smartlab_project_entities (project_id, entity_type, entity_id)
            VALUES (:project_id, :entity_type, :entity_id)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':entity_type', $entityType);
        $req->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    public function removeEntity(int $projectId, int $linkId): void
    {
        $this->assertProjectInTeam($projectId);

        $sql = 'DELETE pe
            FROM smartlab_project_entities AS pe
            INNER JOIN smartlab_projects AS p ON p.id = pe.project_id
            WHERE p.team_id = :team
              AND pe.project_id = :project_id
              AND pe.id = :link_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':link_id', $linkId, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    private function assertProjectInTeam(int $projectId): void
    {
        $sql = 'SELECT id
            FROM smartlab_projects
            WHERE team_id = :team
              AND id = :project_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $this->Db->fetch($req);
    }

    private function assertProjectForCurrentUser(int $projectId): void
    {
        $sql = 'SELECT p.id
            FROM smartlab_projects AS p
            INNER JOIN smartlab_project_members AS pm ON pm.project_id = p.id
            WHERE p.team_id = :team
              AND p.id = :project_id
              AND pm.user_id = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
        $this->Db->fetch($req);
    }
}
