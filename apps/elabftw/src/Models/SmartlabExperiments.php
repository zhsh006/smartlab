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

use function array_filter;
use function array_map;
use function count;
use function date;
use function implode;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function pow;
use function round;
use function trim;

final class SmartlabExperiments extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        $migrator = new SmartlabSchemaMigrator();
        $migrator->ensureMaterialColumns();
        $migrator->ensureReportMaterialTable();
    }

    public function readProjects(int $experimentId): array
    {
        $sql = 'SELECT
                pe.id AS link_id,
                p.id AS id,
                p.id AS project_id,
                p.code,
                p.name,
                p.status,
                p.updated_at
            FROM smartlab_project_entities AS pe
            INNER JOIN smartlab_projects AS p ON p.id = pe.project_id
            INNER JOIN experiments AS e ON e.id = pe.entity_id
            WHERE pe.entity_type = "experiment"
              AND pe.entity_id = :experiment_id
              AND p.team_id = :team
              AND e.team = :team
            ORDER BY p.updated_at DESC, p.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readAvailableProjects(int $experimentId): array
    {
        $sql = 'SELECT
                p.id,
                p.code,
                p.name,
                p.status
            FROM smartlab_projects AS p
            WHERE p.team_id = :team
              AND NOT EXISTS (
                SELECT 1
                FROM smartlab_project_entities AS pe
                WHERE pe.project_id = p.id
                  AND pe.entity_type = "experiment"
                  AND pe.entity_id = :experiment_id
              )
            ORDER BY p.updated_at DESC, p.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readReviewFlows(int $experimentId): array
    {
        $sql = 'SELECT
                rf.id,
                rf.project_id,
                rf.status,
                rf.created_at,
                rf.updated_at,
                p.code AS project_code,
                p.name AS project_name,
                CONCAT(req.firstname, " ", req.lastname) AS requester_name,
                CONCAT(assignee.firstname, " ", assignee.lastname) AS assignee_name,
                rt.decision,
                rt.comment,
                rt.decided_at
            FROM smartlab_review_flows AS rf
            LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
            LEFT JOIN users AS req ON req.userid = rf.requester_userid
            LEFT JOIN smartlab_review_tasks AS rt ON rt.flow_id = rf.id
            LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
            WHERE rf.entity_type = "experiment"
              AND rf.entity_id = :experiment_id
              AND (p.team_id = :team OR rf.requester_userid = :userid)
            ORDER BY rf.updated_at DESC, rf.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readSamples(int $experimentId): array
    {
        $sql = 'SELECT
                se.id AS event_id,
                s.id AS sample_id,
                s.code,
                s.name,
                s.sample_type,
                s.status,
                s.quantity,
                s.unit,
                s.updated_at,
                se.event_type,
                se.quantity_delta,
                se.note,
                se.occurred_at,
                p.id AS project_id,
                p.code AS project_code,
                p.name AS project_name
            FROM smartlab_sample_events AS se
            INNER JOIN smartlab_samples AS s ON s.id = se.sample_id
            LEFT JOIN smartlab_projects AS p ON p.id = s.project_id
            INNER JOIN experiments AS e ON e.id = se.experiment_id
            WHERE se.experiment_id = :experiment_id
              AND e.team = :team
              AND s.team_id = :team
            ORDER BY se.occurred_at DESC, se.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readAvailableSamples(int $experimentId): array
    {
        $sql = 'SELECT
                s.id,
                s.code,
                s.name,
                s.sample_type,
                s.status,
                s.quantity,
                s.unit,
                p.code AS project_code,
                p.name AS project_name
            FROM smartlab_samples AS s
            LEFT JOIN smartlab_projects AS p ON p.id = s.project_id
            WHERE s.team_id = :team
              AND NOT EXISTS (
                SELECT 1
                FROM smartlab_sample_events AS se
                WHERE se.sample_id = s.id
                  AND se.experiment_id = :experiment_id
              )
            ORDER BY s.updated_at DESC, s.id DESC
            LIMIT 100';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readLots(int $experimentId): array
    {
        (new SmartlabSchemaMigrator())->ensureUsageRecordsTable();

        $sql = 'SELECT
                u.id AS usage_id,
                u.usage_source,
                u.usage_type,
                u.material_label,
                u.material_brand,
                u.catalog_no,
                u.lot_no,
                u.location_label,
                u.quantity,
                u.unit,
                u.purpose,
                u.note,
                u.occurred_at,
                l.id AS lot_id,
                l.quantity AS lot_quantity,
                l.expires_at,
                m.code AS material_code,
                m.name AS material_name,
                m.unit AS material_unit,
                i.id AS item_id,
                i.title AS item_title,
                i.custom_id AS item_custom_id
            FROM smartlab_experiment_usage_records AS u
            LEFT JOIN smartlab_material_lots AS l ON l.id = u.lot_id
            LEFT JOIN smartlab_materials AS m ON m.id = l.material_id
            LEFT JOIN items AS i ON i.id = m.source_item_id
            INNER JOIN experiments AS e ON e.id = u.experiment_id
            WHERE u.experiment_id = :experiment_id
              AND u.team_id = :team
              AND e.team = :team
            ORDER BY u.occurred_at DESC, u.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readAvailableLots(): array
    {
        $sql = 'SELECT
                l.id AS lot_id,
                l.lot_no,
                l.expires_at,
                l.quantity,
                l.location_label,
                m.id AS material_id,
                m.code AS material_code,
                m.name AS material_name,
                m.category,
                COALESCE(NULLIF(m.brand, ""), NULLIF(m.manufacturer, "")) AS brand,
                m.manufacturer,
                m.catalog_no,
                m.specification,
                m.package_size,
                m.unit,
                i.id AS item_id,
                i.title AS item_title,
                i.custom_id AS item_custom_id
            FROM smartlab_material_lots AS l
            INNER JOIN smartlab_materials AS m ON m.id = l.material_id
            LEFT JOIN items AS i ON i.id = m.source_item_id
            WHERE m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("sample", "samples", "instrument", "instruments", "样本", "仪器"))
              AND l.lot_no <> "AUTO-CATALOG"
              AND COALESCE(l.quantity, 0) > 0
            ORDER BY
                CASE WHEN COALESCE(l.quantity, 0) <= 2 THEN 0 ELSE 1 END ASC,
                CASE WHEN l.expires_at IS NOT NULL AND l.expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 0 ELSE 1 END ASC,
                l.updated_at DESC,
                l.id DESC
            LIMIT 100';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    private function readAvailableLotById(int $lotId): array
    {
        $sql = 'SELECT
                l.id AS lot_id,
                l.lot_no,
                l.quantity,
                l.expires_at,
                l.location_label,
                l.note,
                m.code AS material_code,
                m.name AS material_name,
                m.category,
                COALESCE(NULLIF(m.brand, ""), NULLIF(m.manufacturer, "")) AS brand,
                m.manufacturer,
                m.catalog_no,
                m.specification,
                m.package_size,
                m.unit,
                i.id AS item_id,
                i.title AS item_title,
                i.custom_id AS item_custom_id
            FROM smartlab_material_lots AS l
            INNER JOIN smartlab_materials AS m ON m.id = l.material_id
            LEFT JOIN items AS i ON i.id = m.source_item_id
            WHERE l.id = :lot_id
              AND m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("sample", "samples", "instrument", "instruments", "样本", "仪器"))
              AND l.lot_no <> "AUTO-CATALOG"
              AND COALESCE(l.quantity, 0) > 0
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':lot_id', $lotId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row === false) {
            throw new ResourceNotFoundException('Inventory lot not found.');
        }
        return $row;
    }

    /**
     * @param int[] $experimentIds
     * @return array<int, array{project_count:int, latest_status:?string}>
     */
    public function readStatusMap(array $experimentIds): array
    {
        if (empty($experimentIds)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($experimentIds), '?'));
        $sql = "SELECT
                e.id AS experiment_id,
                COUNT(DISTINCT pe.project_id) AS project_count,
                (
                    SELECT rf2.status
                    FROM smartlab_review_flows AS rf2
                    LEFT JOIN smartlab_projects AS p2 ON p2.id = rf2.project_id
                    WHERE rf2.entity_type = 'experiment'
                      AND rf2.entity_id = e.id
                      AND (p2.team_id = ? OR rf2.requester_userid = ?)
                    ORDER BY rf2.updated_at DESC, rf2.id DESC
                    LIMIT 1
                ) AS latest_status
            FROM experiments AS e
            LEFT JOIN smartlab_project_entities AS pe
              ON pe.entity_type = 'experiment'
             AND pe.entity_id = e.id
            LEFT JOIN smartlab_projects AS p
              ON p.id = pe.project_id
             AND p.team_id = ?
            WHERE e.team = ?
              AND e.id IN ($placeholders)
            GROUP BY e.id";

        $req = $this->Db->prepare($sql);
        $index = 1;
        $req->bindValue($index++, $this->Users->team, PDO::PARAM_INT);
        $req->bindValue($index++, $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue($index++, $this->Users->team, PDO::PARAM_INT);
        $req->bindValue($index++, $this->Users->team, PDO::PARAM_INT);
        foreach ($experimentIds as $experimentId) {
            $req->bindValue($index++, $experimentId, PDO::PARAM_INT);
        }
        $this->Db->execute($req);

        $map = array();
        foreach ($req->fetchAll() as $row) {
            $map[(int) $row['experiment_id']] = array(
                'project_count' => (int) $row['project_count'],
                'latest_status' => $row['latest_status'] ?: null,
            );
        }

        return $map;
    }

    public function linkProject(int $experimentId, int $projectId): void
    {
        $this->assertExperimentInTeam($experimentId);
        $this->assertProjectInTeam($projectId);

        $sql = 'INSERT IGNORE INTO smartlab_project_entities (project_id, entity_type, entity_id)
            VALUES (:project_id, "experiment", :experiment_id)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    public function findProjectIdByCode(string $projectCode): ?int
    {
        $code = trim($projectCode);
        if ($code === '') {
            return null;
        }

        $sql = 'SELECT id
            FROM smartlab_projects
            WHERE team_id = :team
              AND code = :code
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':code', $code);
        $this->Db->execute($req);
        $row = $req->fetch();
        return $row === false ? null : (int) $row['id'];
    }

    public function generateExperimentCode(string $packKey): string
    {
        $prefix = match ($packKey) {
            'western_blot' => 'WB',
            'if' => 'IF',
            'ihc' => 'IHC',
            'elisa' => 'ELISA',
            default => 'QPCR',
        };
        $year = date('Y');
        $pattern = sprintf('EXP-%s-%s-%%', $prefix, $year);
        $sql = 'SELECT COUNT(*) AS count
            FROM experiments
            WHERE team = :team
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.experiment_code")) LIKE :pattern';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':pattern', $pattern);
        $this->Db->execute($req);
        $row = $req->fetch() ?: array('count' => 0);
        return sprintf('EXP-%s-%s-%03d', $prefix, $year, ((int) ($row['count'] ?? 0)) + 1);
    }

    public function unlinkProject(int $experimentId, int $linkId): void
    {
        $this->assertExperimentInTeam($experimentId);

        $sql = 'DELETE pe
            FROM smartlab_project_entities AS pe
            INNER JOIN smartlab_projects AS p ON p.id = pe.project_id
            WHERE pe.id = :link_id
              AND pe.entity_type = "experiment"
              AND pe.entity_id = :experiment_id
              AND p.team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':link_id', $linkId, PDO::PARAM_INT);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    public function submitReview(
        int $experimentId,
        int $projectId,
        string $comment,
        string $reportTitle = '',
        string $studentSummary = '',
        string $discussionPoints = '',
        array $keyUploadIds = array(),
    ): void
    {
        $this->assertExperimentInTeam($experimentId);
        $project = $this->readProjectMeta($projectId);
        $this->linkProject($experimentId, $projectId);

        $existingFlowId = $this->findOpenFlowId($experimentId, $projectId);
        if ($existingFlowId !== null) {
            $sql = 'UPDATE smartlab_review_flows
                SET status = "submitted",
                    updated_at = NOW()
                WHERE id = :flow_id';
            $req = $this->Db->prepare($sql);
            $req->bindValue(':flow_id', $existingFlowId, PDO::PARAM_INT);
            $this->Db->execute($req);

            $sql = 'UPDATE smartlab_review_tasks
                SET decision = NULL,
                    comment = :comment,
                    decided_at = NULL
                WHERE flow_id = :flow_id';
            $req = $this->Db->prepare($sql);
            $req->bindValue(':comment', $comment);
            $req->bindValue(':flow_id', $existingFlowId, PDO::PARAM_INT);
            $this->Db->execute($req);
            $this->saveReportMaterial(
                $experimentId,
                $projectId,
                $existingFlowId,
                $reportTitle,
                $studentSummary,
                $discussionPoints !== '' ? $discussionPoints : $comment,
                $keyUploadIds,
            );
            return;
        }

        $reviewerId = $this->resolveReviewerUserId((int) $project['created_by']);

        $sql = 'INSERT INTO smartlab_review_flows (project_id, entity_type, entity_id, status, requester_userid)
            VALUES (:project_id, "experiment", :experiment_id, "submitted", :requester_userid)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':requester_userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);
        $flowId = $this->Db->lastInsertId();

        $sql = 'INSERT INTO smartlab_review_tasks (flow_id, assignee_userid, comment)
            VALUES (:flow_id, :assignee_userid, :comment)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $req->bindValue(':assignee_userid', $reviewerId, PDO::PARAM_INT);
        $req->bindValue(':comment', $comment);
        $this->Db->execute($req);

        $this->saveReportMaterial(
            $experimentId,
            $projectId,
            (int) $flowId,
            $reportTitle,
            $studentSummary,
            $discussionPoints !== '' ? $discussionPoints : $comment,
            $keyUploadIds,
        );
    }

    private function saveReportMaterial(
        int $experimentId,
        int $projectId,
        int $flowId,
        string $reportTitle,
        string $studentSummary,
        string $discussionPoints,
        array $keyUploadIds,
    ): void {
        $experimentTitle = $this->readExperimentTitle($experimentId);
        $title = trim($reportTitle) !== '' ? trim($reportTitle) : sprintf('实验汇报：%s', $experimentTitle);
        $validUploadIds = $this->filterExperimentUploadIds($experimentId, $keyUploadIds);
        $keyUploadIdsJson = empty($validUploadIds) ? null : json_encode($validUploadIds, JSON_THROW_ON_ERROR);
        $pdfUrl = sprintf('make.php?type=experiments&id=%d&format=pdf', $experimentId);

        $sql = 'SELECT id
            FROM smartlab_experiment_report_materials
            WHERE review_flow_id = :flow_id
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $existing = $req->fetch();

        if ($existing !== false) {
            $sql = 'UPDATE smartlab_experiment_report_materials
                SET title = :title,
                    student_summary = :student_summary,
                    discussion_points = :discussion_points,
                    pdf_url = :pdf_url,
                    key_upload_ids = :key_upload_ids,
                    status = "submitted",
                    mentor_feedback_action = NULL,
                    mentor_feedback = NULL,
                    feedback_by = NULL,
                    feedback_at = NULL,
                    updated_at = NOW()
                WHERE id = :material_id
                  AND team_id = :team';
            $req = $this->Db->prepare($sql);
            $req->bindValue(':material_id', (int) $existing['id'], PDO::PARAM_INT);
        } else {
            $sql = 'INSERT INTO smartlab_experiment_report_materials
                (team_id, project_id, experiment_id, review_flow_id, title, student_summary, discussion_points, pdf_url, key_upload_ids, status, created_by)
                VALUES (:team, :project_id, :experiment_id, :flow_id, :title, :student_summary, :discussion_points, :pdf_url, :key_upload_ids, "submitted", :created_by)';
            $req = $this->Db->prepare($sql);
            $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
            $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
            $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
            $req->bindValue(':created_by', $this->Users->userid, PDO::PARAM_INT);
        }

        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':title', $title);
        $req->bindValue(':student_summary', $studentSummary !== '' ? $studentSummary : null);
        $req->bindValue(':discussion_points', $discussionPoints !== '' ? $discussionPoints : null);
        $req->bindValue(':pdf_url', $pdfUrl);
        if ($keyUploadIdsJson === null) {
            $req->bindValue(':key_upload_ids', null, PDO::PARAM_NULL);
        } else {
            $req->bindValue(':key_upload_ids', $keyUploadIdsJson);
        }
        $this->Db->execute($req);
    }

    private function filterExperimentUploadIds(int $experimentId, array $uploadIds): array
    {
        $uploadIds = array_values(array_unique(array_filter(
            array_map(static fn($uploadId): int => (int) $uploadId, $uploadIds),
            static fn(int $uploadId): bool => $uploadId > 0,
        )));
        if (empty($uploadIds)) {
            return array();
        }

        $placeholders = implode(', ', array_fill(0, count($uploadIds), '?'));
        $sql = sprintf(
            'SELECT id
                FROM uploads
                WHERE item_id = ?
                  AND type = "experiments"
                  AND id IN (%s)
                ORDER BY id ASC',
            $placeholders,
        );
        $req = $this->Db->prepare($sql);
        $req->bindValue(1, $experimentId, PDO::PARAM_INT);
        foreach ($uploadIds as $index => $uploadId) {
            $req->bindValue($index + 2, $uploadId, PDO::PARAM_INT);
        }
        $this->Db->execute($req);

        return array_map(static fn(array $row): int => (int) $row['id'], $req->fetchAll());
    }

    private function readExperimentTitle(int $experimentId): string
    {
        $sql = 'SELECT title
            FROM experiments
            WHERE id = :experiment_id
              AND team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $this->Db->fetch($req);
        return trim((string) ($row['title'] ?? '未命名实验'));
    }

    public function linkSample(int $experimentId, int $sampleId, string $eventType, string $note): void
    {
        $this->assertExperimentInTeam($experimentId);
        $this->assertSampleInTeam($sampleId);

        if (!in_array($eventType, array('used_in_experiment', 'aliquoted', 'consumed'), true)) {
            $eventType = 'used_in_experiment';
        }

        $sql = 'INSERT INTO smartlab_sample_events
            (sample_id, event_type, experiment_id, actor_userid, note, occurred_at)
            VALUES (:sample_id, :event_type, :experiment_id, :actor_userid, :note, NOW())';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $req->bindValue(':event_type', $eventType);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':actor_userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':note', $note);
        $this->Db->execute($req);
    }

    public function linkLot(int $experimentId, int $lotId, string $txnType, float $quantity, string $note): void
    {
        $this->assertExperimentInTeam($experimentId);
        (new SmartlabInventory($this->Users))->addTransaction($lotId, $txnType, $quantity, $note, $experimentId);
    }

    private function normalizeUsageType(string $usageType, string $category = ''): string
    {
        $categoryMap = array(
            'reagent' => 'reagent',
            '试剂' => 'reagent',
            'antibody' => 'antibody',
            '抗体' => 'antibody',
            'primer' => 'primer',
            '引物' => 'primer',
            'kit' => 'kit',
            '试剂盒' => 'kit',
            'consumable' => 'consumable',
            '耗材' => 'consumable',
            'sample' => 'sample',
            '样本' => 'sample',
            'instrument' => 'instrument',
            '仪器' => 'instrument',
            'other' => 'other',
            'key_material' => 'other',
            'key material' => 'other',
            '关键材料' => 'other',
            '其他' => 'other',
        );

        $normalizedCategory = strtolower(trim($category));
        if ($normalizedCategory !== '' && isset($categoryMap[$normalizedCategory])) {
            return $categoryMap[$normalizedCategory];
        }

        $usageType = strtolower(trim($usageType));
        $allowedUsageTypes = array('reagent', 'antibody', 'primer', 'kit', 'consumable', 'sample', 'instrument', 'other');
        return in_array($usageType, $allowedUsageTypes, true) ? $usageType : 'reagent';
    }

    public function recordUsage(int $experimentId, int $lotId, string $usageSource, string $inventoryQuery, string $usageType, string $materialLabel, string $brand, string $catalogNo, string $lotNo, string $locationLabel, float $quantity, string $unit, string $purpose, string $note): void
    {
        $this->assertExperimentInTeam($experimentId);
        $this->ensureUsageRecordsTable();

        $lot = array();
        $normalizedUsageSource = 'manual';
        if ($lotId > 0) {
            $lot = $this->readAvailableLotById($lotId);
        } elseif ($usageSource === 'inventory') {
            $lot = $this->readAvailableLotByQuery($inventoryQuery);
            if ($lot !== array()) {
                $lotId = (int) $lot['lot_id'];
            }
        }

        if ($lot !== array()) {
            $materialLabel = trim($materialLabel) !== '' ? $materialLabel : trim((string) ($lot['material_name'] ?? ''));
            $materialLabel = $materialLabel !== '' ? $materialLabel : trim((string) ($lot['material_code'] ?? ''));
            $brand = trim($brand) !== '' ? $brand : trim((string) ($lot['brand'] ?? ''));
            $brand = $brand !== '' ? $brand : trim((string) ($lot['manufacturer'] ?? ''));
            $catalogNo = trim($catalogNo) !== '' ? $catalogNo : trim((string) ($lot['catalog_no'] ?? ''));
            $catalogNo = $catalogNo !== '' ? $catalogNo : trim((string) ($lot['item_custom_id'] ?? ''));
            $catalogNo = $catalogNo !== '' ? $catalogNo : (string) ($lot['material_code'] ?? '');
            $lotNo = trim($lotNo) !== '' ? $lotNo : (string) ($lot['lot_no'] ?? '');
            $locationLabel = trim($locationLabel) !== '' ? $locationLabel : (string) ($lot['location_label'] ?? '');
            $unit = trim($unit) !== '' ? $unit : (string) ($lot['unit'] ?? '');
            $normalizedUsageSource = 'inventory';
        }

        $usageType = $this->normalizeUsageType($usageType, (string) ($lot['category'] ?? ''));

        $materialLabel = trim($materialLabel);
        if ($materialLabel === '') {
            return;
        }

        $sql = 'INSERT INTO smartlab_experiment_usage_records
            (team_id, experiment_id, lot_id, usage_source, usage_type, material_label, material_brand, catalog_no, lot_no, location_label, quantity, unit, purpose, note, actor_userid, occurred_at)
            VALUES (:team_id, :experiment_id, :lot_id, :usage_source, :usage_type, :material_label, :material_brand, :catalog_no, :lot_no, :location_label, :quantity, :unit, :purpose, :note, :actor_userid, NOW())';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $this->Db->bindNullableInt($req, ':lot_id', $lotId > 0 ? $lotId : null);
        $req->bindValue(':usage_source', $normalizedUsageSource);
        $req->bindValue(':usage_type', $usageType);
        $req->bindValue(':material_label', $materialLabel);
        $req->bindValue(':material_brand', trim($brand) !== '' ? trim($brand) : null);
        $req->bindValue(':catalog_no', trim($catalogNo) !== '' ? trim($catalogNo) : null);
        $req->bindValue(':lot_no', trim($lotNo) !== '' ? trim($lotNo) : null);
        $req->bindValue(':location_label', trim($locationLabel) !== '' ? trim($locationLabel) : null);
        $req->bindValue(':quantity', $quantity > 0 ? $quantity : null);
        $req->bindValue(':unit', trim($unit) !== '' ? trim($unit) : null);
        $req->bindValue(':purpose', trim($purpose) !== '' ? trim($purpose) : null);
        $req->bindValue(':note', trim($note) !== '' ? trim($note) : null);
        $req->bindValue(':actor_userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    private function readAvailableLotByQuery(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return array();
        }
        $query = trim(explode('/', trim(explode('·', $query)[0]))[0]);
        if ($query === '') {
            return array();
        }

        $sql = 'SELECT
                l.id AS lot_id,
                l.lot_no,
                l.quantity,
                l.expires_at,
                l.location_label,
                l.note,
                m.code AS material_code,
                m.name AS material_name,
                m.category,
                COALESCE(NULLIF(m.brand, ""), NULLIF(m.manufacturer, "")) AS brand,
                m.manufacturer,
                m.catalog_no,
                m.specification,
                m.package_size,
                m.unit,
                i.id AS item_id,
                i.title AS item_title,
                i.custom_id AS item_custom_id
            FROM smartlab_material_lots AS l
            INNER JOIN smartlab_materials AS m ON m.id = l.material_id
            LEFT JOIN items AS i ON i.id = m.source_item_id
            WHERE m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("sample", "samples", "instrument", "instruments", "样本", "仪器"))
              AND l.lot_no <> "AUTO-CATALOG"
              AND COALESCE(l.quantity, 0) > 0
              AND (
                m.name LIKE :query
                OR m.code LIKE :query
                OR m.brand LIKE :query
                OR m.manufacturer LIKE :query
                OR m.catalog_no LIKE :query
                OR m.specification LIKE :query
                OR m.package_size LIKE :query
                OR l.lot_no LIKE :query
                OR l.location_label LIKE :query
                OR i.title LIKE :query
                OR i.custom_id LIKE :query
                OR l.note LIKE :query
              )
            ORDER BY
                CASE WHEN m.name = :exact_query OR m.code = :exact_query OR m.catalog_no = :exact_query OR l.lot_no = :exact_query THEN 0 ELSE 1 END ASC,
                CASE WHEN COALESCE(l.quantity, 0) <= 2 THEN 0 ELSE 1 END ASC,
                l.updated_at DESC,
                l.id DESC
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':query', '%' . $query . '%');
        $req->bindValue(':exact_query', $query);
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row === false) {
            return array();
        }
        return $row;
    }

    public function readAssayPack(int $experimentId): array
    {
        $this->assertExperimentInTeam($experimentId);

        $default = array(
            'id' => null,
            'pack_key' => '',
            'pack_mode' => 'quick',
            'payload' => $this->getDefaultAssayPayload(),
            'results' => $this->getDefaultQpcrResults(),
            'qc_status' => 'draft',
            'qc_feedback' => array(),
            'auto_summary' => '',
            'analysis_cards' => array(),
            'analysis_rows' => array(),
            'analysis_bars' => array(),
            'workflow' => $this->buildWorkflowState('', $this->getDefaultAssayPayload(), $this->getDefaultQpcrResults(), array(), $this->countExperimentUploads($experimentId), $this->hasSubmittedReview($experimentId)),
        );

        $sql = 'SELECT id, pack_key, pack_mode, payload, qc_status, qc_summary, auto_summary
            FROM smartlab_assay_packs
            WHERE experiment_id = :experiment_id
              AND team_id = :team
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row === false) {
            return $default;
        }

        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            $payload = $this->getDefaultAssayPayload();
        }

        $packKey = (string) $row['pack_key'];
        $results = array();
        if ($packKey === 'qpcr') {
            $resultsReq = $this->Db->prepare('SELECT
                    row_index,
                    sample_label,
                    group_label,
                    target_gene,
                    ct_value,
                    reference_ct,
                    delta_ct,
                    delta_delta_ct,
                    fold_change,
                    replicate_count,
                    is_outlier,
                    note
                FROM smartlab_assay_pack_results
                WHERE assay_pack_id = :assay_pack_id
                ORDER BY row_index ASC, id ASC');
            $resultsReq->bindValue(':assay_pack_id', (int) $row['id'], PDO::PARAM_INT);
            $this->Db->execute($resultsReq);
            $results = $resultsReq->fetchAll();
            if ($results === array()) {
                $results = $this->getDefaultQpcrResults();
            }
        }

        $qcSummary = json_decode((string) ($row['qc_summary'] ?? '[]'), true);
        $feedback = is_array($qcSummary) && isset($qcSummary['feedback']) && is_array($qcSummary['feedback'])
            ? $qcSummary['feedback']
            : array();

        $mergedPayload = $this->applyAssayCoverDefaults(
            $packKey,
            array_replace_recursive($this->getDefaultAssayPayload($packKey), $payload),
        );
        $uploadCount = $this->countExperimentUploads($experimentId);
        $hasSubmittedReview = $this->hasSubmittedReview($experimentId);

        return array(
            'id' => (int) $row['id'],
            'pack_key' => $packKey,
            'pack_mode' => (string) $row['pack_mode'],
            'payload' => $mergedPayload,
            'results' => $results,
            'qc_status' => (string) $row['qc_status'],
            'qc_feedback' => $feedback,
            'auto_summary' => (string) ($row['auto_summary'] ?? ''),
            'analysis_cards' => $this->buildAnalysisCards(
                $packKey,
                $mergedPayload,
                $results,
                $feedback,
            ),
            'analysis_rows' => $this->buildAnalysisRows(
                $packKey,
                $mergedPayload,
                $results,
            ),
            'analysis_group_rows' => $packKey === 'qpcr' ? $this->buildQpcrGroupAnalysisRows($results) : array(),
            'analysis_bars' => $this->buildAnalysisBars(
                $packKey,
                $mergedPayload,
                $results,
            ),
            'analysis_group_bars' => $packKey === 'qpcr' ? $this->buildQpcrGroupAnalysisBars($results) : array(),
            'workflow' => $this->buildWorkflowState($packKey, $mergedPayload, $results, $feedback, $uploadCount, $hasSubmittedReview),
        );
    }

    private function countExperimentUploads(int $experimentId): int
    {
        $sql = 'SELECT COUNT(*) AS upload_count
            FROM uploads
            WHERE item_id = :experiment_id
              AND type = "experiments"';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $this->Db->fetch($req);
        return (int) ($row['upload_count'] ?? 0);
    }

    private function hasSubmittedReview(int $experimentId): bool
    {
        $sql = 'SELECT COUNT(*) AS flow_count
            FROM smartlab_review_flows
            WHERE entity_type = "experiment"
              AND entity_id = :experiment_id
              AND status IN ("submitted", "approved", "needs_revision")';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $this->Db->fetch($req);
        return (int) ($row['flow_count'] ?? 0) > 0;
    }

    private function buildWorkflowState(string $packKey, array $payload, array $results, array $feedback, int $uploadCount, bool $hasSubmittedReview): array
    {
        $hasPack = $packKey !== '';
        $hasPlan = $hasPack;

        $requiredMaterialLabels = $this->getWorkflowRequiredMaterialLabels($packKey, $payload);
        $missingMaterials = array_values(array_filter(
            $requiredMaterialLabels,
            static fn(array $item): bool => trim((string) ($item['value'] ?? '')) === '',
        ));
        $hasMaterials = $hasPack && $missingMaterials === array();
        $canPrintBenchSheet = $hasPlan && $hasMaterials;
        $hasRawData = $uploadCount > 0;
        $hasRecognitionEvidence = $this->hasWorkflowRecognitionEvidence($packKey, $payload);
        $hasRecognizedResults = $this->hasWorkflowResults($packKey, $payload, $results);
        $canSubmitReview = $canPrintBenchSheet && $hasRawData && $hasRecognizedResults;

        $steps = array(
            array('key' => 'prepare', 'label' => '实验准备', 'done' => $canPrintBenchSheet, 'active' => !$canPrintBenchSheet),
            array('key' => 'upload', 'label' => '上传原始数据', 'done' => $hasRawData, 'active' => $canPrintBenchSheet && !$hasRawData),
            array('key' => 'recognize', 'label' => '辅助检查（可选）', 'done' => $hasRecognitionEvidence, 'active' => $hasRawData && !$hasRecognitionEvidence),
            array('key' => 'results', 'label' => '结果回填确认', 'done' => $hasRecognizedResults, 'active' => $hasRecognitionEvidence && !$hasRecognizedResults),
            array('key' => 'submit', 'label' => '提交导师审核', 'done' => $hasSubmittedReview, 'active' => $canSubmitReview && !$hasSubmittedReview),
        );

        $activeSet = false;
        foreach ($steps as &$step) {
            $step['active'] = !$activeSet && !$step['done'] && $step['key'] !== 'recognize';
            $activeSet = $activeSet || $step['active'];
        }
        unset($step);

        $studentChecks = $this->buildStudentWorkflowChecks($packKey, $payload, $feedback, $missingMaterials, $uploadCount, $hasRecognitionEvidence, $hasRecognizedResults, $hasPlan);
        $mentorChecks = $this->buildMentorWorkflowChecks($packKey, $payload, $feedback, $missingMaterials, $uploadCount, $hasRecognitionEvidence, $hasRecognizedResults);
        $workflowDemo = $this->buildWorkflowDemo($packKey, $payload, $feedback, $missingMaterials, $uploadCount, $hasRecognitionEvidence, $hasRecognizedResults);

        return array(
            'steps' => $steps,
            'has_basic_info' => $hasPlan,
            'has_materials' => $hasMaterials,
            'can_print_bench_sheet' => $canPrintBenchSheet,
            'has_raw_data' => $hasRawData,
            'has_recognition_evidence' => $hasRecognitionEvidence,
            'has_recognized_results' => $hasRecognizedResults,
            'can_submit_review' => $canSubmitReview,
            'has_submitted_review' => $hasSubmittedReview,
            'upload_count' => $uploadCount,
            'required_materials' => $requiredMaterialLabels,
            'missing_materials' => $missingMaterials,
            'student_checks' => $studentChecks,
            'mentor_checks' => $mentorChecks,
            'workflow_demo' => $workflowDemo,
            'patent_demo' => $workflowDemo,
        );
    }

    private function buildWorkflowDemo(string $packKey, array $payload, array $feedback, array $missingMaterials, int $uploadCount, bool $hasRecognitionEvidence, bool $hasRecognizedResults): array
    {
        if ($packKey !== 'western_blot') {
            return array();
        }

        $filledRows = count($this->getFilledGenericResultRows($payload));
        $missingLabels = array_values(array_map(
            static fn(array $item): string => (string) ($item['label'] ?? ''),
            $missingMaterials,
        ));
        $hasRawData = $uploadCount > 0;
        $hasMissingMaterials = $missingMaterials !== array();
        $hasFeedback = $feedback !== array();

        $status = static function (bool $done, bool $active = false): string {
            if ($done) {
                return '已完成';
            }
            return $active ? '当前处理' : '待完成';
        };
        $tone = static function (bool $done, bool $active = false): string {
            if ($done) {
                return 'success';
            }
            return $active ? 'warning' : 'neutral';
        };

        $templateSteps = array(
            array(
                'num' => '01',
                'label' => '样本准备',
                'detail' => trim((string) ($payload['sample']['sample_origin'] ?? '')) ?: '等待选择 WB 样本批次',
                'status' => $status(trim((string) ($payload['sample']['sample_origin'] ?? '')) !== ''),
                'tone' => $tone(trim((string) ($payload['sample']['sample_origin'] ?? '')) !== ''),
            ),
            array(
                'num' => '02',
                'label' => '跑胶与转膜',
                'detail' => trim((string) ($payload['run']['gel_percentage'] ?? '')) !== ''
                    ? trim((string) ($payload['run']['gel_percentage'] ?? '')) . ' / ' . (trim((string) ($payload['run']['transfer_condition'] ?? '')) ?: '转膜条件待确认')
                    : '等待胶浓度、转膜条件或膜对象',
                'status' => $status(trim((string) ($payload['run']['gel_percentage'] ?? '')) !== '' && trim((string) ($payload['run']['transfer_condition'] ?? '')) !== ''),
                'tone' => $tone(trim((string) ($payload['run']['gel_percentage'] ?? '')) !== '' && trim((string) ($payload['run']['transfer_condition'] ?? '')) !== ''),
            ),
            array(
                'num' => '03',
                'label' => '抗体孵育',
                'detail' => trim((string) ($payload['reagent']['primary_antibody'] ?? '')) !== ''
                    ? trim((string) ($payload['reagent']['primary_antibody'] ?? '')) . ' / ' . (trim((string) ($payload['reagent']['secondary_antibody'] ?? '')) ?: '二抗待确认')
                    : '等待一抗、二抗对象',
                'status' => $status(trim((string) ($payload['reagent']['primary_antibody'] ?? '')) !== '' && trim((string) ($payload['reagent']['secondary_antibody'] ?? '')) !== ''),
                'tone' => $tone(trim((string) ($payload['reagent']['primary_antibody'] ?? '')) !== '' && trim((string) ($payload['reagent']['secondary_antibody'] ?? '')) !== ''),
            ),
            array(
                'num' => '04',
                'label' => '成像识别',
                'detail' => $hasRecognitionEvidence ? '已完成 lane/ROI 识别并关联 WB 原图证据' : ($hasRawData ? '已上传附件，等待 lane/ROI 识别' : '等待上传 WB 原图或仪器导出文件'),
                'status' => $status($hasRecognitionEvidence, $hasRawData && !$hasRecognitionEvidence),
                'tone' => $tone($hasRecognitionEvidence, $hasRawData && !$hasRecognitionEvidence),
            ),
            array(
                'num' => '05',
                'label' => '灰度定量',
                'detail' => $hasRecognizedResults ? sprintf('%d 行结构化结果已回填', $filledRows) : '等待目标/内参灰度与归一化结果',
                'status' => $status($hasRecognizedResults, $hasRecognitionEvidence && !$hasRecognizedResults),
                'tone' => $tone($hasRecognizedResults, $hasRecognitionEvidence && !$hasRecognizedResults),
            ),
        );

        $gapMessage = '当前 WB 步骤链未发现明显跳步风险。';
        $gapTone = 'success';
        if ($hasMissingMaterials) {
            $gapMessage = '疑似缺失关键材料或步骤字段：' . implode('、', array_filter($missingLabels)) . '。';
            $gapTone = 'warning';
        } elseif (!$hasRawData) {
            $gapMessage = '缺少原始数据附件，无法闭合 WB 证据链。';
            $gapTone = 'warning';
        } elseif (!$hasRecognitionEvidence) {
            $gapMessage = '已有原始附件，但尚未完成 WB lane/ROI 识别与图像证据关联。';
            $gapTone = 'warning';
        } elseif (!$hasRecognizedResults) {
            $gapMessage = '已有图像证据，但结构化灰度结果尚未回填。';
            $gapTone = 'warning';
        } elseif ($hasFeedback) {
            $gapMessage = implode('；', array_map(static fn($item): string => (string) $item, $feedback));
            $gapTone = 'warning';
        }

        return array(
            'summary' => array(
                array('label' => '附件证据', 'value' => sprintf('%d 个', $uploadCount), 'tone' => $hasRawData ? 'success' : 'neutral'),
                array('label' => '识别证据', 'value' => $hasRecognitionEvidence ? '已关联' : '待关联', 'tone' => $hasRecognitionEvidence ? 'success' : ($hasRawData ? 'warning' : 'neutral')),
                array('label' => '结构化结果', 'value' => sprintf('%d 行', $filledRows), 'tone' => $hasRecognizedResults ? 'success' : ($hasRecognitionEvidence ? 'warning' : 'neutral')),
                array('label' => '待补全项', 'value' => (string) count($missingMaterials), 'tone' => $hasMissingMaterials ? 'warning' : 'success'),
            ),
            'modules' => array(
                array('label' => '采集模块', 'detail' => $hasRawData ? '已接收 WB 原图、曝光图或仪器导出文件。' : '等待上传原始数据作为采集输入。', 'tone' => $hasRawData ? 'success' : 'neutral'),
                array('label' => '记录模块', 'detail' => $hasRecognitionEvidence ? '已形成原图、lane/ROI 标注和结构化字段索引。' : '上传后会建立图像、附件名和结构化字段索引。', 'tone' => $hasRecognitionEvidence ? 'success' : ($hasRawData ? 'warning' : 'neutral')),
                array('label' => '断点识别模块', 'detail' => $hasFeedback ? '存在质控提示，需复核断点原因。' : '按当前五步流程检查材料、附件、识别证据和结果连续性。', 'tone' => $hasFeedback ? 'warning' : 'success'),
                array('label' => '跳步识别模块', 'detail' => $gapMessage, 'tone' => ($hasMissingMaterials || !$hasRawData || ($hasRawData && !$hasRecognizedResults) || $hasFeedback) ? 'warning' : 'success'),
                array('label' => '模板补全模块', 'detail' => $hasRecognizedResults ? sprintf('可将 %d 行灰度结果写入 WB 过程草稿并送审。', $filledRows) : '等待结果回填后生成完整 WB 过程草稿。', 'tone' => $hasRecognizedResults ? 'success' : 'neutral'),
            ),
            'template_steps' => $templateSteps,
            'gap_message' => $gapMessage,
            'gap_tone' => $gapTone,
        );
    }

    private function getWorkflowRequiredMaterialLabels(string $packKey, array $payload): array
    {
        return match ($packKey) {
            'western_blot' => array(
                $this->buildRequiredMaterialItem('WB样本批次', $payload['sample']['sample_origin'] ?? ''),
                $this->buildRequiredMaterialItem('一抗', $payload['reagent']['primary_antibody'] ?? '', $payload['reagent']['primary_object'] ?? array(), 'antibody'),
                $this->buildRequiredMaterialItem('二抗', $payload['reagent']['secondary_antibody'] ?? '', $payload['reagent']['secondary_object'] ?? array(), 'antibody'),
                $this->buildRequiredMaterialItem('显色试剂', $payload['reagent']['detection_reagent'] ?? '', $payload['reagent']['detection_object'] ?? array(), 'reagent'),
                $this->buildRequiredMaterialItem('膜类型', $payload['run']['membrane_type'] ?? '', $payload['reagent']['membrane_object'] ?? array(), 'reagent'),
            ),
            'if' => array(
                $this->buildRequiredMaterialItem('细胞/组织', $payload['sample']['sample_origin'] ?? ''),
                $this->buildRequiredMaterialItem('封闭液', $payload['staining']['blocking_buffer'] ?? '', $payload['staining']['blocking_object'] ?? array(), 'reagent'),
                $this->buildRequiredMaterialItem('一抗', $payload['staining']['primary_antibody'] ?? '', $payload['staining']['primary_object'] ?? array(), 'antibody'),
                $this->buildRequiredMaterialItem('二抗', $payload['staining']['secondary_antibody'] ?? '', $payload['staining']['secondary_object'] ?? array(), 'antibody'),
                $this->buildRequiredMaterialItem('显微镜', $payload['imaging']['microscope'] ?? '', $payload['imaging']['microscope_object'] ?? array(), 'reagent'),
            ),
            'ihc' => array(
                $this->buildRequiredMaterialItem('组织来源', $payload['sample']['tissue_id'] ?? ''),
                $this->buildRequiredMaterialItem('固定液', $payload['sample']['fixative'] ?? '', $payload['sample']['fixative_object'] ?? array(), 'reagent'),
                $this->buildRequiredMaterialItem('抗原修复', $payload['staining']['antigen_retrieval'] ?? '', $payload['staining']['antigen_retrieval_object'] ?? array(), 'reagent'),
                $this->buildRequiredMaterialItem('一抗', $payload['staining']['primary_antibody'] ?? '', $payload['staining']['primary_object'] ?? array(), 'antibody'),
                $this->buildRequiredMaterialItem('二抗', $payload['staining']['secondary_antibody'] ?? '', $payload['staining']['secondary_object'] ?? array(), 'antibody'),
            ),
            'elisa' => array(
                $this->buildRequiredMaterialItem('样本来源', $payload['sample']['sample_origin'] ?? ''),
                $this->buildRequiredMaterialItem('检测指标', $payload['reagent']['target_name'] ?? ''),
                $this->buildRequiredMaterialItem('试剂盒', $payload['reagent']['kit_name'] ?? '', $payload['reagent']['kit_object'] ?? array(), 'kit'),
                $this->buildRequiredMaterialItem('读板仪', $payload['run']['reader_model'] ?? '', $payload['run']['reader_object'] ?? array(), 'reagent'),
            ),
            default => array(
                $this->buildRequiredMaterialItem('样本来源', $payload['sample']['sample_origin'] ?? ''),
                $this->buildRequiredMaterialItem('引物对象', $payload['primer']['primer_code'] ?? '', $payload['primer']['object'] ?? array(), 'primer'),
                $this->buildRequiredMaterialItem('试剂盒', $payload['setup']['kit_name'] ?? '', $payload['setup']['kit_object'] ?? array(), 'kit'),
                $this->buildRequiredMaterialItem('仪器型号', $payload['setup']['instrument_model'] ?? '', $payload['setup']['instrument_object'] ?? array(), 'reagent'),
            ),
        };
    }

    private function buildRequiredMaterialItem(string $label, mixed $value, array $object = array(), string $inventoryCategory = ''): array
    {
        $displayValue = trim((string) $value);
        if ($displayValue === '') {
            $displayValue = trim((string) ($object['name'] ?? ''));
        }
        $status = $inventoryCategory !== '' && $displayValue !== ''
            ? $this->readInventoryMaterialStatus($displayValue, (string) ($object['code'] ?? ''), $inventoryCategory)
            : array();

        return array(
            'label' => $label,
            'value' => $displayValue,
            'inventory_status' => $status['status'] ?? '',
            'inventory_label' => $status['label'] ?? '',
        );
    }

    private function readInventoryMaterialStatus(string $name, string $objectCode, string $category): array
    {
        $codes = array_filter(array_unique(array($objectCode, $objectCode !== '' ? 'MAT-' . $objectCode : '')));
        $codeParams = array();
        foreach (array_values($codes) as $index => $code) {
            $param = ':code_' . $index;
            $codeParams[$param] = $code;
        }
        $strictCategory = in_array(strtolower($category), array('antibody', 'primer'), true);
        $sql = 'SELECT m.code, m.name, COALESCE(SUM(l.quantity), 0) AS available_quantity, COUNT(l.id) AS lot_count
            FROM smartlab_materials AS m
            LEFT JOIN smartlab_material_lots AS l ON l.material_id = m.id
            WHERE m.team_id = :team
              ' . ($strictCategory ? 'AND LOWER(m.category) = LOWER(:category)' : '') . '
              AND (m.name = :name'
            . ($codeParams !== array() ? ' OR m.code IN (' . implode(',', array_keys($codeParams)) . ')' : '')
            . ')
            GROUP BY m.id, m.code, m.name
            ORDER BY available_quantity DESC, lot_count DESC
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if ($strictCategory) {
            $req->bindValue(':category', $category);
        }
        $req->bindValue(':name', $name);
        foreach ($codeParams as $param => $code) {
            $req->bindValue($param, $code);
        }
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row === false) {
            return array('status' => 'missing', 'label' => '库存未建档');
        }
        if ((float) ($row['available_quantity'] ?? 0) > 0) {
            return array('status' => 'available', 'label' => '库存已匹配');
        }
        return array('status' => 'pending_lot', 'label' => '库存数量为 0');
    }

    private function hasWorkflowResults(string $packKey, array $payload, array $results): bool
    {
        if ($packKey === '') {
            return false;
        }
        if ($packKey === 'qpcr') {
            foreach ($results as $row) {
                if (trim((string) ($row['sample_label'] ?? '')) !== '' && (($row['ct_value'] ?? null) !== null || ($row['fold_change'] ?? null) !== null)) {
                    return true;
                }
            }
            return false;
        }
        return $this->getFilledGenericResultRows($payload) !== array();
    }

    private function hasWorkflowRecognitionEvidence(string $packKey, array $payload): bool
    {
        if ($packKey === '') {
            return false;
        }
        if ($packKey === 'western_blot') {
            foreach (array(
                $payload['image']['raw_image_ref'] ?? '',
                $payload['image']['cropped_image_ref'] ?? '',
                $payload['image']['annotation_note'] ?? '',
                $payload['qc']['notes'] ?? '',
            ) as $value) {
                if (trim((string) $value) !== '') {
                    return true;
                }
            }
            return false;
        }
        foreach (($payload['image'] ?? array()) as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }

    private function buildStudentWorkflowChecks(string $packKey, array $payload, array $feedback, array $missingMaterials, int $uploadCount, bool $hasRecognitionEvidence, bool $hasRecognizedResults, bool $hasPlan): array
    {
        $checks = array();
        if (!$hasPlan) {
            $checks[] = array('type' => 'step', 'tone' => 'warning', 'label' => '实验计划检查', 'message' => '先选择实验类型，系统会自动带入实验目的和操作者，再进入样本与材料确认。');
        }
        foreach ($missingMaterials as $item) {
            $checks[] = array('type' => 'step', 'tone' => 'warning', 'label' => '样本/材料检查', 'message' => sprintf('缺少%s，请先从样本、库存或对象字典补齐。', (string) $item['label']));
        }
        if ($uploadCount === 0) {
            $checks[] = array('type' => 'data', 'tone' => 'neutral', 'label' => '原始数据检查', 'message' => '还没有上传原始数据；实验结束后上传仪器表、图片或分析文件。');
        } elseif (!$hasRecognitionEvidence) {
            $checks[] = array('type' => 'data', 'tone' => 'warning', 'label' => '智能识别检查', 'message' => '已上传原始数据，请先关联原图/裁剪图并完成 WB 断点检查。');
        } elseif (!$hasRecognizedResults) {
            $checks[] = array('type' => 'data', 'tone' => 'warning', 'label' => '数据异常检查', 'message' => '已上传原始数据，但结构化结果表还没有形成可确认结果。');
        }
        foreach ($feedback as $item) {
            $checks[] = array('type' => 'data', 'tone' => 'warning', 'label' => '数据异常检查', 'message' => (string) $item);
        }
        if ($checks === array()) {
            $checks[] = array('type' => 'ok', 'tone' => 'success', 'label' => '待复核项', 'message' => '当前没有发现必须阻断流程的缺项；可按顺序继续。');
        }
        return $checks;
    }

    private function buildMentorWorkflowChecks(string $packKey, array $payload, array $feedback, array $missingMaterials, int $uploadCount, bool $hasRecognitionEvidence, bool $hasRecognizedResults): array
    {
        $checks = array();
        foreach ($missingMaterials as $item) {
            $checks[] = array('type' => 'jump', 'tone' => 'warning', 'label' => '跳步识别', 'message' => sprintf('疑似缺失步骤或关键材料：%s。', (string) $item['label']));
        }
        if ($uploadCount === 0) {
            $checks[] = array('type' => 'jump', 'tone' => 'warning', 'label' => '跳步识别', 'message' => '缺少原始数据附件，无法完成结果证据链。');
        } elseif (!$hasRecognitionEvidence) {
            $checks[] = array('type' => 'breakpoint', 'tone' => 'warning', 'label' => '断点识别', 'message' => '已有原始附件但尚未关联图像证据或完成断点检查。');
        } elseif (!$hasRecognizedResults) {
            $checks[] = array('type' => 'jump', 'tone' => 'warning', 'label' => '跳步识别', 'message' => '已有原始附件但未形成结构化结果，疑似缺少数据解析/结果确认步骤。');
        }
        foreach ($feedback as $item) {
            $checks[] = array('type' => 'breakpoint', 'tone' => 'warning', 'label' => '断点识别', 'message' => (string) $item);
        }
        if ($checks === array()) {
            $checks[] = array('type' => 'ok', 'tone' => 'success', 'label' => '断点/跳步识别', 'message' => '当前未发现明显断点或跳步风险。');
        }
        return $checks;
    }

    public function getAvailableAssayPacks(): array
    {
        return array(
            array(
                'key' => 'qpcr',
                'label' => 'qPCR Assay Pack',
                'description' => '样本、引物、扩增条件、结果表和质控提示一起录入。',
                'status' => 'active',
            ),
            array(
                'key' => 'western_blot',
                'label' => 'Western Blot',
                'description' => '抗体、图像和灰度定量整合包。',
                'status' => 'active',
            ),
            array(
                'key' => 'if',
                'label' => 'Immunofluorescence',
                'description' => '染色流程、成像参数和荧光定量包。',
                'status' => 'active',
            ),
            array(
                'key' => 'ihc',
                'label' => 'Immunohistochemistry',
                'description' => '病理评分、定位描述和图像记录包。',
                'status' => 'active',
            ),
            array(
                'key' => 'elisa',
                'label' => 'ELISA',
                'description' => '标准曲线、孔板读数和浓度回算整合包。',
                'status' => 'active',
            ),
        );
    }

    public function getAssayPresetCatalog(): array
    {
        return array(
            'qpcr' => array(
                'objectives' => array('表达量比较', '处理组/对照组差异验证', '时间梯度表达分析', '剂量反应表达分析', '关键基因筛选验证', '样本质量复核'),
                'operators' => array('当前登录用户', '学生操作者', '助理复核录入', '导师指定操作者', '仪器自动记录'),
                'sample_origins' => array('关联样本自动带入', '细胞样本', '组织样本', '血清/血浆样本', '裂解液样本', 'RNA 提取物', 'cDNA 样本'),
                'treatment_groups' => array('Control', 'Vehicle', 'Treatment A', 'Treatment B', 'Positive control', 'Negative control', 'Dose low', 'Dose middle', 'Dose high', 'Time 0h', 'Time 6h', 'Time 24h', 'Time 48h'),
                'rna_concentrations' => array('50-100 ng/uL', '100-300 ng/uL', '300-600 ng/uL', '>600 ng/uL', '待 Nanodrop/Qubit 回填'),
                'a260_a280_values' => array('1.80-2.00 合格', '2.00-2.10 合格', '<1.80 需复核', '>2.10 需复核', '未检测'),
                'rt_batches' => array('RT-当日批次', 'RT-上批次复用', 'RT kit lot 见库存', '无需逆转录', '待助理补批次'),
                'target_genes' => array('ACTB', 'GAPDH', 'BDNF', 'GFAP', 'IL6', 'TNF', 'IL1B', 'NOS2', 'ARG1', 'MKI67', 'CASP3', 'BAX', 'BCL2', 'MAP2', 'RBFOX3', 'SYP', 'SNAP25', 'COL1A1', 'ACTA2', 'VEGFA', 'GAP43'),
                'reference_genes' => array('GAPDH', 'ACTB', '18S rRNA', 'HPRT1', 'TBP', 'RPL13A'),
                'kit_names' => array(
                    'TB Green Premix Ex Taq II',
                    'ChamQ Universal SYBR qPCR Master Mix',
                    'PowerUp SYBR Green Master Mix',
                    'PrimeScript RT reagent Kit',
                ),
                'instrument_models' => array('ABI QuantStudio 5', 'Bio-Rad CFX96', 'Roche LightCycler 480'),
                'reaction_volumes' => array('10 uL', '20 uL', '25 uL', '384-well 10 uL', '96-well 20 uL'),
                'annealing_temperatures' => array('58 C', '60 C', '62 C', '按引物对象回填'),
                'amplicon_ranges' => array('80-120 bp', '100-150 bp', '150-200 bp', '按引物对象回填'),
                'cycler_programs' => array('95 C 30s; 40 cycles: 95 C 5s / 60 C 30s; melting curve', '95 C 3min; 40 cycles: 95 C 10s / 60 C 30s; melting curve', '按试剂盒 SOP', '按仪器模板'),
                'result_notes' => array('自动导入', '人工确认', '重复孔一致', '重复孔需复核', '未检出', 'Ct 偏高需复核', '异常孔已剔除'),
                'qc_notes' => array('质控通过', '阴性对照需复核', '熔解曲线需复核', '重复孔差异需复核', '样本浓度需复核'),
            ),
            'western_blot' => array(
                'operators' => array('当前登录用户', '学生操作者', '助理复核录入', '导师指定操作者', '成像系统自动记录'),
                'sample_origins' => array('关联 WB-ready 样本自动带入', '已加 loading buffer 蛋白样本', '细胞总蛋白样本批次', '组织总蛋白样本批次', '核蛋白样本批次', '胞浆蛋白样本批次', '培养上清浓缩样本批次'),
                'loading_amounts' => array('10 ug/lane', '20 ug/lane', '30 ug/lane', '40 ug/lane', '等体积上样'),
                'denaturation_conditions' => array('95 C 5 min', '95 C 10 min', '70 C 10 min', '不加热，按抗体说明', '按样本 SOP'),
                'gel_percentages' => array('8%', '10%', '12%', '15%', '4-12% gradient', '10-20% gradient'),
                'electrophoresis_conditions' => array('80 V stacking / 120 V resolving', '100 V constant', '120 V constant', '按预制胶 SOP'),
                'transfer_conditions' => array('Wet transfer 300 mA 90 min', 'Wet transfer 100 V 90 min', 'Semi-dry 25 V 7 min', 'Semi-dry 15 V 45 min', '按膜/设备 SOP'),
                'primary_antibodies' => array('Anti-BDNF', 'Anti-GFAP', 'Anti-MAP2', 'Anti-GAPDH', 'Anti-beta-Actin'),
                'secondary_antibodies' => array('Goat anti-Rabbit HRP', 'Goat anti-Mouse HRP', 'Donkey anti-Rabbit IRDye 800CW'),
                'detection_reagents' => array('ECL Prime', 'SuperSignal West Femto', 'Clarity Western ECL'),
                'membrane_types' => array('PVDF', 'Nitrocellulose'),
                'dilutions' => array('1:500', '1:1000', '1:2000', '1:5000', 'Ready-to-use', '按抗体对象建议'),
                'target_band_kda' => array('15-25 kDa', '25-50 kDa', '50-75 kDa', '75-100 kDa', '>100 kDa', '按抗体 datasheet'),
                'reference_controls' => array('GAPDH', 'ACTB / beta-Actin', 'Tubulin', 'Histone H3', 'Total protein stain'),
                'normalization_methods' => array('Target / GAPDH', 'Target / ACTB', 'Target / Tubulin', 'Target / Total protein', 'Fold vs control'),
                'trends' => array('up', 'down', 'stable', 'not detected', 'need repeat', '自动回填'),
                'result_notes' => array('自动分析', '人工确认', '条带清晰', '条带弱需复核', '背景偏高', '内参异常', '需重跑'),
                'image_notes' => array('原始图已上传', '裁剪图已生成', '标注图已生成', 'Lane/ROI 已确认', '需重新裁剪', '待上传附件'),
                'conclusions' => array('上调', '下调', '无显著变化', '趋势性变化，需增加重复', '结果需复核'),
                'qc_notes' => array('质控通过', '转膜需复核', '背景偏高', '重复一致性需复核', '内参异常'),
            ),
            'if' => array(
                'operators' => array('当前登录用户', '学生操作者', '助理复核录入', '导师指定操作者', '显微镜自动记录'),
                'sample_origins' => array('关联样本自动带入', '贴壁细胞', '悬浮细胞涂片', '冰冻切片', '石蜡切片', '类器官/球体'),
                'seeding_densities' => array('5,000 cells/well', '10,000 cells/well', '20,000 cells/well', '50,000 cells/well', '70-80% confluence'),
                'treatment_conditions' => array('Control', 'Vehicle', 'Treatment A 24h', 'Treatment B 24h', 'Positive control', 'Negative control', '按项目方案'),
                'fixation_methods' => array('4% PFA 15 min', '4% PFA 20 min', 'Cold methanol 10 min', 'Acetone 10 min', '按抗体说明'),
                'blocking_buffers' => array('5% BSA', '5% Normal Goat Serum', '1% BSA + 0.1% Triton X-100'),
                'permeabilizations' => array('0.1% Triton X-100 10 min', '0.2% Triton X-100 10 min', '0.3% Triton X-100 15 min', 'Saponin 0.1%', '不透膜'),
                'primary_antibodies' => array('Anti-GFAP', 'Anti-NeuN', 'Anti-Iba1', 'Anti-MAP2'),
                'secondary_antibodies' => array('Alexa Fluor 488 Goat anti-Rabbit', 'Alexa Fluor 594 Goat anti-Mouse', 'Alexa Fluor 647 Donkey anti-Goat'),
                'dapi' => array('DAPI 1 ug/mL', 'Hoechst 33342'),
                'dark_incubations' => array('室温避光 30 min', '室温避光 60 min', '4 C 避光过夜', '按二抗说明'),
                'microscopes' => array('Leica SP8', 'Zeiss LSM 980', 'Nikon A1R'),
                'objectives' => array('10x', '20x', '40x', '60x oil', '63x oil'),
                'excitations' => array('DAPI / 405 nm', 'FITC / 488 nm', 'TRITC / 561 nm', 'Cy5 / 640 nm', '405/488/561/640 nm'),
                'exposures' => array('自动曝光锁定', '同批统一曝光', '低曝光', '中曝光', '高曝光', '按阴性对照设定'),
                'batches' => array('同日同批拍摄', '分批拍摄已校准', '重复拍摄', '待补拍'),
                'field_counts' => array('3 fields/sample', '5 fields/sample', '10 fields/sample', 'Whole well scan', '按 ROI 模板'),
                'field_labels' => array('Field 1', 'Field 2', 'Field 3', 'Field 4', 'Field 5', 'ROI 1', 'ROI 2', 'Whole well', '自动识别视野'),
                'cell_counts' => array('自动回填', '<100 cells', '100-300 cells', '300-500 cells', '>500 cells', '需复核'),
                'positive_ratios' => array('自动回填', '<1%', '1-5%', '5-25%', '25-50%', '50-75%', '>75%', '需复核'),
                'mean_intensities' => array('自动回填', '低', '中', '高', '背景偏高需复核', '曝光饱和需复核'),
                'colocalizations' => array('自动回填', '低共定位', '中等共定位', '高共定位', '阴性/无共定位', '需复核'),
                'image_notes' => array('原始图已上传', 'Merge 图已生成', '标注图已生成', 'ROI 已确认', '需补拍', '待上传附件'),
                'result_notes' => array('自动分析', '人工确认', '背景可接受', '阴性对照需复核', '曝光不一致', '需补拍'),
                'conclusions' => array('阳性比例升高', '阳性比例降低', '荧光强度升高', '荧光强度降低', '无明显变化', '需补充图像'),
                'qc_notes' => array('质控通过', '阴性对照需复核', '背景偏高', '曝光不一致', '图像需重拍'),
            ),
            'ihc' => array(
                'operators' => array('当前登录用户', '学生操作者', '助理复核录入', '导师指定操作者', '扫描仪自动记录'),
                'tissue_ids' => array('关联样本自动带入', 'Tumor tissue', 'Normal adjacent tissue', 'Brain section', 'Liver section', 'Kidney section', 'Skin section'),
                'antigen_retrievals' => array('Citrate buffer pH 6.0', 'EDTA buffer pH 9.0', 'Tris-EDTA pH 9.0'),
                'embedding_batches' => array('FFPE batch A', 'FFPE batch B', 'Frozen batch A', '同日包埋批次', '待助理补批次'),
                'section_thicknesses' => array('3 um', '4 um', '5 um', '8 um', '10 um'),
                'blocking_buffers' => array('5% BSA 30 min', 'Normal goat serum 30 min', '3% H2O2 10 min + serum block', 'Commercial IHC blocking buffer'),
                'primary_antibodies' => array('Anti-Ki67', 'Anti-GFAP', 'Anti-Iba1', 'Anti-NeuN'),
                'secondary_antibodies' => array('HRP Polymer anti-Rabbit', 'HRP Polymer anti-Mouse'),
                'dab_times' => array('30 s', '1 min', '2 min', '3 min', '5 min', '显微镜下控制'),
                'counterstains' => array('Hematoxylin', 'Mayer Hematoxylin'),
                'fixatives' => array('10% Neutral Buffered Formalin', '4% Paraformaldehyde'),
                'localizations' => array('Nuclear', 'Cytoplasmic', 'Membranous', 'Nuclear + cytoplasmic', 'Stromal', 'Vascular', 'Diffuse'),
                'positive_areas' => array('<1%', '1-5%', '5-25%', '25-50%', '50-75%', '>75%', '算法自动回填'),
                'staining_intensities' => array('0 negative', '1 weak', '2 moderate', '3 strong', 'heterogeneous', '算法自动回填'),
                'h_scores' => array('0-50', '51-100', '101-200', '201-300', '算法自动回填'),
                'region_labels' => array('低倍全景', '高倍视野 1', '高倍视野 2', '高倍视野 3', 'ROI 1', 'ROI 2', '热点区域', '阴性对照', '阳性对照', '算法自动识别区域'),
                'irs_scores' => array('0', '1-3', '4-6', '7-9', '10-12', '算法自动回填', '需复核'),
                'image_notes' => array('低倍图已上传', '高倍图已上传', '局部放大已生成', '标注图已生成', 'ROI 已确认', '待上传附件'),
                'result_notes' => array('自动评分', '人工确认', '背景可接受', '阳性对照通过', '评分需复核', '需补拍'),
                'conclusions' => array('阳性增强', '阳性降低', '定位符合预期', '背景需复核', '评分需复核', '无明显变化'),
                'qc_notes' => array('质控通过', '背景偏高', '阳性对照需复核', '阴性对照需复核', '评分需复核'),
            ),
            'elisa' => array(
                'operators' => array('当前登录用户', '学生操作者', '助理复核录入', '导师指定操作者', '读板仪自动记录'),
                'kit_names' => array('Rat BDNF ELISA Kit', 'Mouse IL-6 ELISA Kit', 'Human TNF-alpha ELISA Kit'),
                'targets' => array('BDNF', 'IL6', 'TNF-alpha', 'GFAP'),
                'sample_origins' => array('关联样本自动带入', '血清', '血浆', '细胞上清', '组织匀浆', '细胞裂解液', '标准品'),
                'sample_types' => array('Serum', 'Plasma', 'Cell culture supernatant', 'Tissue homogenate', 'Cell lysate', 'Standard', 'Blank'),
                'dilution_factors' => array('原液', '1:2', '1:5', '1:10', '1:20', '1:50', '1:100', '按试剂盒说明'),
                'batch_ids' => array('Plate 1', 'Plate 2', '同日同板', '跨板校准', '待助理补批次'),
                'capture_antibodies' => array('Anti-BDNF Capture', 'Anti-IL6 Capture'),
                'detection_antibodies' => array('Biotin Anti-BDNF', 'Biotin Anti-IL6'),
                'substrates' => array('TMB Substrate', 'TMB One-Step'),
                'readers' => array('BioTek Synergy H1', 'Thermo Multiskan FC', 'Molecular Devices SpectraMax iD3'),
                'wavelengths' => array('450 nm', '450/570 nm', '450/620 nm', '405 nm', 'OD450 按试剂盒'),
                'plate_layouts' => array('Blank + standards + samples duplicate', 'Blank + standards + samples triplicate', 'Standards left / samples right', '96-well full plate', '按导入文件'),
                'incubation_conditions' => array('37 C 60 min', '37 C 90 min', 'Room temperature 2 h', '4 C overnight', '按试剂盒说明'),
                'wash_cycles' => array('3 times', '4 times', '5 times', '自动洗板程序 A', '按试剂盒说明'),
                'standard_curve_r2' => array('>=0.990 合格', '>=0.995 合格', '<0.990 需复核', '软件自动回填'),
                'blank_od450' => array('<0.100 合格', '<0.150 合格', '<0.200 合格', '自动回填', '需复核'),
                'cv_thresholds' => array('10%', '15%', '20%', '按试剂盒说明'),
                'result_notes' => array('自动导入', '人工确认', '重复孔一致', '重复 CV 偏高', '超出标准曲线', '需稀释复测', '需复孔'),
                'conclusions' => array('浓度升高', '浓度降低', '无明显变化', '超出标准曲线范围', '需稀释复测', '需重复检测'),
                'qc_notes' => array('质控通过', '空白孔偏高', '标准曲线需复核', '重复 CV 超阈值', '样本需稀释复测'),
            ),
        );
    }

    public function getAssayObjectCatalog(): array
    {
        return array(
            'primers' => $this->readPrimerCatalog(),
            'antibodies' => $this->readAntibodyCatalog(),
            'reagents' => $this->readReagentCatalog(),
        );
    }

    public function saveAssayPack(int $experimentId, array $input, bool $initializing = false): void
    {
        $this->assertExperimentInTeam($experimentId);
        $Entity = new Experiments($this->Users, $experimentId);
        $Entity->readOne(); $Entity->canOrExplode(\Elabftw\Enums\AccessType::Write);
        if ($Entity->isReadOnly) { throw new \Elabftw\Exceptions\AppException('记录已锁定或只读。'); }
        $Bench = new SmartlabBenchRecords($this->Users);

        $packKey = trim((string) ($input['pack_key'] ?? ''));
        if (!in_array($packKey, $this->getSupportedAssayPackKeys(), true)) {
            return;
        }

        $packMode = trim((string) ($input['pack_mode'] ?? 'quick'));
        if (!in_array($packMode, array('quick', 'standard'), true)) {
            $packMode = 'quick';
        }

        $payload = $this->normalizeAssayPayload($packKey, $input, $packMode);
        $results = $packKey === 'qpcr' ? $this->normalizeQpcrResults($input) : array();
        $qcSummary = $packKey === 'qpcr'
            ? $this->buildQpcrQcSummary($payload, $results)
            : $this->buildGenericQcSummary($packKey, $payload);
        $autoSummary = $packKey === 'qpcr'
            ? $this->buildQpcrAutoSummary($payload, $results, $qcSummary)
            : $this->buildGenericAutoSummary($packKey, $payload, $qcSummary);

        $sql = 'INSERT INTO smartlab_assay_packs
            (experiment_id, team_id, pack_key, pack_mode, payload, qc_status, qc_summary, auto_summary, created_by, updated_by)
            VALUES
            (:experiment_id, :team_id, :pack_key, :pack_mode, :payload, :qc_status, :qc_summary, :auto_summary, :created_by, :updated_by)
            ON DUPLICATE KEY UPDATE
            pack_key = VALUES(pack_key),
            pack_mode = VALUES(pack_mode),
            payload = VALUES(payload),
            qc_status = VALUES(qc_status),
            qc_summary = VALUES(qc_summary),
            auto_summary = VALUES(auto_summary),
            updated_by = VALUES(updated_by)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team_id', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':pack_key', $packKey);
        $req->bindValue(':pack_mode', $packMode);
        $req->bindValue(':payload', json_encode($payload, JSON_THROW_ON_ERROR));
        $req->bindValue(':qc_status', $qcSummary['status']);
        $req->bindValue(':qc_summary', json_encode($qcSummary, JSON_THROW_ON_ERROR));
        $req->bindValue(':auto_summary', $autoSummary);
        $req->bindValue(':created_by', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':updated_by', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);

        $packId = $this->findAssayPackId($experimentId);

        $deleteReq = $this->Db->prepare('DELETE FROM smartlab_assay_pack_results WHERE assay_pack_id = :assay_pack_id');
        $deleteReq->bindValue(':assay_pack_id', $packId, PDO::PARAM_INT);
        $this->Db->execute($deleteReq);

        $insertSql = 'INSERT INTO smartlab_assay_pack_results
            (assay_pack_id, row_index, sample_label, group_label, target_gene, ct_value, reference_ct, delta_ct, delta_delta_ct, fold_change, replicate_count, is_outlier, note)
            VALUES
            (:assay_pack_id, :row_index, :sample_label, :group_label, :target_gene, :ct_value, :reference_ct, :delta_ct, :delta_delta_ct, :fold_change, :replicate_count, :is_outlier, :note)';
        if ($packKey === 'qpcr') {
            foreach ($results as $index => $row) {
                $insertReq = $this->Db->prepare($insertSql);
                $insertReq->bindValue(':assay_pack_id', $packId, PDO::PARAM_INT);
                $insertReq->bindValue(':row_index', $index + 1, PDO::PARAM_INT);
                $insertReq->bindValue(':sample_label', $row['sample_label']);
                $insertReq->bindValue(':group_label', $row['group_label']);
                $insertReq->bindValue(':target_gene', $row['target_gene']);
                $this->bindNullableFloat($insertReq, ':ct_value', $row['ct_value']);
                $this->bindNullableFloat($insertReq, ':reference_ct', $row['reference_ct']);
                $this->bindNullableFloat($insertReq, ':delta_ct', $row['delta_ct']);
                $this->bindNullableFloat($insertReq, ':delta_delta_ct', $row['delta_delta_ct']);
                $this->bindNullableFloat($insertReq, ':fold_change', $row['fold_change']);
                $this->Db->bindNullableInt($insertReq, ':replicate_count', $row['replicate_count']);
                $insertReq->bindValue(':is_outlier', $row['is_outlier'], PDO::PARAM_INT);
                $insertReq->bindValue(':note', $row['note']);
                $this->Db->execute($insertReq);
            }
        }
        $Bench->recordParameters($experimentId, $packKey, $payload, $results, $initializing);
    }

    public function initializeAssayPack(int $experimentId, string $packKey, string $packMode = 'quick', array $initialInput = array()): void
    {
        if (!in_array($packKey, $this->getSupportedAssayPackKeys(), true)) {
            return;
        }

        $defaults = array(
            'pack_key' => $packKey,
            'pack_mode' => $packMode,
            'assay_objective' => $this->getAssayDefaultObjective($packKey),
            'assay_project_code' => trim((string) ($initialInput['project_code'] ?? '')),
            'assay_operator_name' => $this->getCurrentUserFullname(),
            'assay_sample_source' => '',
            'assay_run_date' => date('Y-m-d'),
        );
        $targetName = trim((string) ($initialInput['target_name'] ?? ''));
        $packDefaults = match ($packKey) {
            'western_blot' => array(
                'wb_primary_antibody' => $targetName,
                'wb_secondary_antibody' => '',
                'wb_conclusion' => '',
            ),
            'if' => array(
                'if_primary_antibody' => $targetName,
                'if_conclusion' => '',
            ),
            'ihc' => array(
                'ihc_primary_antibody' => '',
                'ihc_conclusion' => '',
            ),
            'elisa' => array(
                'elisa_target_name' => $targetName,
                'elisa_conclusion' => '',
            ),
            default => array(
                'qpcr_target_gene' => $targetName,
            ),
        };

        $this->saveAssayPack(
            $experimentId,
            array_replace($defaults, $packDefaults),
            true,
        );

        // Generate and persist experiment_code for new assay-pack experiments
        $this->ensureExperimentCode($experimentId, $packKey);
    }

    private function ensureExperimentCode(int $experimentId, string $packKey): void
    {
        // Read existing metadata first to avoid overwriting an already-generated code.
        $readSql = 'SELECT metadata FROM experiments WHERE id = :experiment_id AND team = :team LIMIT 1';
        $readReq = $this->Db->prepare($readSql);
        $readReq->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $readReq->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($readReq);
        $existingRow = $this->Db->fetch($readReq);
        $existingMeta = array();
        if ($existingRow !== false) {
            $decoded = json_decode((string) ($existingRow['metadata'] ?? ''), true);
            if (is_array($decoded)) {
                $existingMeta = $decoded;
            }
        }

        // Do not regenerate if an experiment_code already exists.
        if (!empty($existingMeta['experiment_code'])) {
            return;
        }

        $prefix = match ($packKey) {
            'qpcr' => 'QPCR',
            'western_blot' => 'WB',
            'if' => 'IF',
            'ihc' => 'IHC',
            'elisa' => 'ELISA',
            default => 'EXP',
        };
        $year = date('Y');
        $seqSql = "SELECT COUNT(*) + 1 AS next_seq
            FROM experiments
            WHERE team = :team
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.experiment_code')) LIKE :like_pattern";
        $seqReq = $this->Db->prepare($seqSql);
        $seqReq->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $seqReq->bindValue(':like_pattern', 'EXP-' . $prefix . '-' . $year . '-%');
        $this->Db->execute($seqReq);
        $seqRow = $this->Db->fetch($seqReq);
        $nextSeq = (int) ($seqRow['next_seq'] ?? 1);
        $experimentCode = sprintf('EXP-%s-%s-%03d', $prefix, $year, $nextSeq);

        $existingMeta['experiment_code'] = $experimentCode;

        $updateSql = 'UPDATE experiments SET metadata = :metadata WHERE id = :experiment_id AND team = :team';
        $updateReq = $this->Db->prepare($updateSql);
        $updateReq->bindValue(':metadata', json_encode($existingMeta, JSON_THROW_ON_ERROR));
        $updateReq->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $updateReq->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($updateReq);
    }

    private function assertExperimentInTeam(int $experimentId): void
    {
        $sql = 'SELECT id
            FROM experiments
            WHERE id = :experiment_id
              AND team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $this->Db->fetch($req);
    }

    private function assertProjectInTeam(int $projectId): void
    {
        $this->readProjectMeta($projectId);
    }

    private function assertSampleInTeam(int $sampleId): void
    {
        $sql = 'SELECT id
            FROM smartlab_samples
            WHERE id = :sample_id
              AND team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $this->Db->fetch($req);
    }

    /**
     * @return array<string, mixed>
     * @throws ResourceNotFoundException
     */
    private function readProjectMeta(int $projectId): array
    {
        $sql = 'SELECT id, created_by
            FROM smartlab_projects
            WHERE id = :project_id
              AND team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $this->Db->fetch($req);
    }

    private function findOpenFlowId(int $experimentId, int $projectId): ?int
    {
        $sql = 'SELECT id
            FROM smartlab_review_flows
            WHERE project_id = :project_id
              AND entity_type = "experiment"
              AND entity_id = :experiment_id
              AND status IN ("submitted", "needs_revision")
            ORDER BY id DESC
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        return $row === false ? null : (int) $row['id'];
    }

    private function resolveReviewerUserId(int $preferredUserId): int
    {
        if ($preferredUserId > 0 && $preferredUserId !== (int) $this->Users->userid) {
            return $preferredUserId;
        }

        $sql = 'SELECT u2t.users_id
            FROM users2teams AS u2t
            INNER JOIN users AS user ON user.userid = u2t.users_id
            WHERE teams_id = :team
              AND u2t.is_admin = 1
              AND u2t.users_id != :userid
            ORDER BY user.is_sysadmin ASC, u2t.is_owner DESC, u2t.users_id ASC
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row !== false) {
            return (int) $row['users_id'];
        }

        return (int) $this->Users->userid;
    }

    private function findAssayPackId(int $experimentId): int
    {
        $req = $this->Db->prepare('SELECT id
            FROM smartlab_assay_packs
            WHERE experiment_id = :experiment_id
              AND team_id = :team
            LIMIT 1');
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $this->Db->fetch($req);
        return (int) $row['id'];
    }

    /** @deprecated Use SmartlabSchemaMigrator::ensureUsageRecordsTable() instead */
    private function ensureUsageRecordsTable(): void
    {
        (new SmartlabSchemaMigrator())->ensureUsageRecordsTable();
    }

    private function normalizeAssayPayload(string $packKey, array $input, string $packMode): array
    {
        $qpcrPrimerObjectId = (int) ($input['qpcr_primer_object_id'] ?? 0);
        $qpcrKitObjectId = (int) ($input['qpcr_kit_object_id'] ?? 0);
        $qpcrInstrumentObjectId = (int) ($input['qpcr_instrument_object_id'] ?? 0);
        $wbPrimaryObjectId = (int) ($input['wb_primary_object_id'] ?? 0);
        $wbSecondaryObjectId = (int) ($input['wb_secondary_object_id'] ?? 0);
        $wbDetectionObjectId = (int) ($input['wb_detection_object_id'] ?? 0);
        $wbMembraneObjectId = (int) ($input['wb_membrane_object_id'] ?? 0);
        $ifPrimaryObjectId = (int) ($input['if_primary_object_id'] ?? 0);
        $ifSecondaryObjectId = (int) ($input['if_secondary_object_id'] ?? 0);
        $ifBlockingObjectId = (int) ($input['if_blocking_object_id'] ?? 0);
        $ifMicroscopeObjectId = (int) ($input['if_microscope_object_id'] ?? 0);
        $ihcPrimaryObjectId = (int) ($input['ihc_primary_object_id'] ?? 0);
        $ihcSecondaryObjectId = (int) ($input['ihc_secondary_object_id'] ?? 0);
        $ihcFixativeObjectId = (int) ($input['ihc_fixative_object_id'] ?? 0);
        $ihcRetrievalObjectId = (int) ($input['ihc_antigen_retrieval_object_id'] ?? 0);
        $ihcCounterstainObjectId = (int) ($input['ihc_counterstain_object_id'] ?? 0);
        $elisaCaptureObjectId = (int) ($input['elisa_capture_object_id'] ?? 0);
        $elisaDetectionObjectId = (int) ($input['elisa_detection_object_id'] ?? 0);
        $elisaKitObjectId = (int) ($input['elisa_kit_object_id'] ?? 0);
        $elisaSubstrateObjectId = (int) ($input['elisa_substrate_object_id'] ?? 0);
        $elisaReaderObjectId = (int) ($input['elisa_reader_object_id'] ?? 0);

        $cover = array(
            'experiment_type' => $this->getAssayPackDisplayLabel($packKey),
            'objective' => trim((string) ($input['assay_objective'] ?? '')),
            'project_code' => trim((string) ($input['assay_project_code'] ?? '')),
            'operator_name' => trim((string) ($input['assay_operator_name'] ?? '')),
            'sample_source' => trim((string) ($input['assay_sample_source'] ?? '')),
            'run_date' => trim((string) ($input['assay_run_date'] ?? '')),
            'linked_sample_id' => (int) ($input['assay_linked_sample_id'] ?? 0),
            'linked_lot_id' => (int) ($input['assay_linked_lot_id'] ?? 0),
        );

        $payload = match ($packKey) {
            'western_blot' => array(
                'cover' => $cover,
                'sample' => array(
                    'sample_origin' => trim((string) ($input['wb_sample_origin'] ?? '')),
                    'loading_amount' => trim((string) ($input['wb_loading_amount'] ?? '')),
                    'denaturation_condition' => trim((string) ($input['wb_denaturation_condition'] ?? '')),
                ),
                'run' => array(
                    'gel_percentage' => trim((string) ($input['wb_gel_percentage'] ?? '')),
                    'electrophoresis_condition' => trim((string) ($input['wb_electrophoresis_condition'] ?? '')),
                    'transfer_condition' => trim((string) ($input['wb_transfer_condition'] ?? '')),
                    'membrane_type' => trim((string) ($input['wb_membrane_type'] ?? '')),
                    'mode' => $packMode,
                ),
                'reagent' => array(
                    'primary_antibody' => trim((string) ($input['wb_primary_antibody'] ?? '')),
                    'primary_dilution' => trim((string) ($input['wb_primary_dilution'] ?? '')),
                    'primary_object' => $this->readAntibodyObjectRef($wbPrimaryObjectId),
                    'secondary_antibody' => trim((string) ($input['wb_secondary_antibody'] ?? '')),
                    'secondary_dilution' => trim((string) ($input['wb_secondary_dilution'] ?? '')),
                    'secondary_object' => $this->readAntibodyObjectRef($wbSecondaryObjectId),
                    'detection_reagent' => trim((string) ($input['wb_detection_reagent'] ?? '')),
                    'detection_object' => $this->readReagentObjectRef($wbDetectionObjectId),
                    'membrane_object' => $this->readReagentObjectRef($wbMembraneObjectId),
                ),
                'analysis' => array(
                    'target_band_kda' => trim((string) ($input['wb_target_band_kda'] ?? '')),
                    'reference_control' => trim((string) ($input['wb_reference_control'] ?? '')),
                    'normalization_method' => trim((string) ($input['wb_normalization_method'] ?? '')),
                    'conclusion' => trim((string) ($input['wb_conclusion'] ?? '')),
                ),
                'results' => $this->normalizeGenericRows(
                    labels: $input['wb_result_sample_label'] ?? array(),
                    metrics: array(
                        'target_signal' => $input['wb_result_target_signal'] ?? array(),
                        'reference_signal' => $input['wb_result_reference_signal'] ?? array(),
                        'normalized_signal' => $input['wb_result_normalized_signal'] ?? array(),
                        'trend' => $input['wb_result_trend'] ?? array(),
                        'note' => $input['wb_result_note'] ?? array(),
                    ),
                    minimumRows: 1,
                ),
                'image' => array(
                    'raw_image_ref' => trim((string) ($input['wb_raw_image_ref'] ?? '')),
                    'cropped_image_ref' => trim((string) ($input['wb_cropped_image_ref'] ?? '')),
                    'annotation_note' => trim((string) ($input['wb_annotation_note'] ?? '')),
                ),
                'qc' => array(
                    'transfer_uniform' => trim((string) ($input['wb_transfer_uniform'] ?? 'yes')),
                    'background_clean' => trim((string) ($input['wb_background_clean'] ?? 'yes')),
                    'replicate_consistent' => trim((string) ($input['wb_replicate_consistent'] ?? 'yes')),
                    'notes' => trim((string) ($input['wb_qc_notes'] ?? '')),
                ),
            ),
            'if' => array(
                'cover' => $cover,
                'sample' => array(
                    'sample_origin' => trim((string) ($input['if_sample_origin'] ?? '')),
                    'seeding_density' => trim((string) ($input['if_seeding_density'] ?? '')),
                    'treatment_condition' => trim((string) ($input['if_treatment_condition'] ?? '')),
                    'fixation_method' => trim((string) ($input['if_fixation_method'] ?? '')),
                ),
                'staining' => array(
                    'blocking_buffer' => trim((string) ($input['if_blocking_buffer'] ?? '')),
                    'permeabilization' => trim((string) ($input['if_permeabilization'] ?? '')),
                    'primary_antibody' => trim((string) ($input['if_primary_antibody'] ?? '')),
                    'primary_object' => $this->readAntibodyObjectRef($ifPrimaryObjectId),
                    'secondary_antibody' => trim((string) ($input['if_secondary_antibody'] ?? '')),
                    'secondary_object' => $this->readAntibodyObjectRef($ifSecondaryObjectId),
                    'dapi' => trim((string) ($input['if_dapi'] ?? '')),
                    'dark_incubation' => trim((string) ($input['if_dark_incubation'] ?? '')),
                    'blocking_object' => $this->readReagentObjectRef($ifBlockingObjectId),
                ),
                'imaging' => array(
                    'microscope' => trim((string) ($input['if_microscope'] ?? '')),
                    'objective' => trim((string) ($input['if_objective'] ?? '')),
                    'excitation' => trim((string) ($input['if_excitation'] ?? '')),
                    'exposure' => trim((string) ($input['if_exposure'] ?? '')),
                    'batch' => trim((string) ($input['if_batch'] ?? '')),
                    'microscope_object' => $this->readReagentObjectRef($ifMicroscopeObjectId),
                    'mode' => $packMode,
                ),
                'analysis' => array(
                    'field_count' => trim((string) ($input['if_field_count'] ?? '')),
                    'positive_ratio' => trim((string) ($input['if_positive_ratio'] ?? '')),
                    'mean_intensity' => trim((string) ($input['if_mean_intensity'] ?? '')),
                    'colocalization' => trim((string) ($input['if_colocalization'] ?? '')),
                    'conclusion' => trim((string) ($input['if_conclusion'] ?? '')),
                ),
                'results' => $this->normalizeGenericRows(
                    labels: $input['if_result_field_label'] ?? array(),
                    metrics: array(
                        'cell_count' => $input['if_result_cell_count'] ?? array(),
                        'positive_ratio' => $input['if_result_positive_ratio'] ?? array(),
                        'mean_intensity' => $input['if_result_mean_intensity'] ?? array(),
                        'colocalization' => $input['if_result_colocalization'] ?? array(),
                        'note' => $input['if_result_note'] ?? array(),
                    ),
                    minimumRows: 3,
                ),
                'image' => array(
                    'raw_image_ref' => trim((string) ($input['if_raw_image_ref'] ?? '')),
                    'merged_image_ref' => trim((string) ($input['if_merged_image_ref'] ?? '')),
                    'annotation_note' => trim((string) ($input['if_annotation_note'] ?? '')),
                ),
                'qc' => array(
                    'negative_control_ok' => trim((string) ($input['if_negative_control_ok'] ?? 'yes')),
                    'background_ok' => trim((string) ($input['if_background_ok'] ?? 'yes')),
                    'exposure_consistent' => trim((string) ($input['if_exposure_consistent'] ?? 'yes')),
                    'notes' => trim((string) ($input['if_qc_notes'] ?? '')),
                ),
            ),
            'ihc' => array(
                'cover' => $cover,
                'sample' => array(
                    'tissue_id' => trim((string) ($input['ihc_tissue_id'] ?? '')),
                    'fixative' => trim((string) ($input['ihc_fixative'] ?? '')),
                    'embedding_batch' => trim((string) ($input['ihc_embedding_batch'] ?? '')),
                    'section_thickness' => trim((string) ($input['ihc_section_thickness'] ?? '')),
                    'fixative_object' => $this->readReagentObjectRef($ihcFixativeObjectId),
                ),
                'staining' => array(
                    'antigen_retrieval' => trim((string) ($input['ihc_antigen_retrieval'] ?? '')),
                    'antigen_retrieval_object' => $this->readReagentObjectRef($ihcRetrievalObjectId),
                    'blocking' => trim((string) ($input['ihc_blocking'] ?? '')),
                    'primary_antibody' => trim((string) ($input['ihc_primary_antibody'] ?? '')),
                    'primary_object' => $this->readAntibodyObjectRef($ihcPrimaryObjectId),
                    'secondary_antibody' => trim((string) ($input['ihc_secondary_antibody'] ?? '')),
                    'secondary_object' => $this->readAntibodyObjectRef($ihcSecondaryObjectId),
                    'dab_time' => trim((string) ($input['ihc_dab_time'] ?? '')),
                    'counterstain' => trim((string) ($input['ihc_counterstain'] ?? '')),
                    'counterstain_object' => $this->readReagentObjectRef($ihcCounterstainObjectId),
                ),
                'analysis' => array(
                    'localization' => trim((string) ($input['ihc_localization'] ?? '')),
                    'positive_area' => trim((string) ($input['ihc_positive_area'] ?? '')),
                    'staining_intensity' => trim((string) ($input['ihc_staining_intensity'] ?? '')),
                    'h_score' => trim((string) ($input['ihc_h_score'] ?? '')),
                    'conclusion' => trim((string) ($input['ihc_conclusion'] ?? '')),
                ),
                'results' => $this->normalizeGenericRows(
                    labels: $input['ihc_result_region_label'] ?? array(),
                    metrics: array(
                        'positive_ratio' => $input['ihc_result_positive_ratio'] ?? array(),
                        'intensity_score' => $input['ihc_result_intensity_score'] ?? array(),
                        'h_score' => $input['ihc_result_h_score'] ?? array(),
                        'irs_score' => $input['ihc_result_irs_score'] ?? array(),
                        'note' => $input['ihc_result_note'] ?? array(),
                    ),
                    minimumRows: 3,
                ),
                'image' => array(
                    'low_magnification_ref' => trim((string) ($input['ihc_low_magnification_ref'] ?? '')),
                    'high_magnification_ref' => trim((string) ($input['ihc_high_magnification_ref'] ?? '')),
                    'zoomed_region_ref' => trim((string) ($input['ihc_zoomed_region_ref'] ?? '')),
                    'analysis_images' => $this->normalizeAnalysisImagesJson((string) ($input['ihc_analysis_images_json'] ?? '')),
                ),
                'qc' => array(
                    'background_ok' => trim((string) ($input['ihc_background_ok'] ?? 'yes')),
                    'positive_control_ok' => trim((string) ($input['ihc_positive_control_ok'] ?? 'yes')),
                    'scoring_complete' => trim((string) ($input['ihc_scoring_complete'] ?? 'yes')),
                    'notes' => trim((string) ($input['ihc_qc_notes'] ?? '')),
                ),
            ),
            'elisa' => array(
                'cover' => $cover,
                'sample' => array(
                    'sample_origin' => trim((string) ($input['elisa_sample_origin'] ?? '')),
                    'sample_type' => trim((string) ($input['elisa_sample_type'] ?? '')),
                    'dilution_factor' => trim((string) ($input['elisa_dilution_factor'] ?? '')),
                    'batch_id' => trim((string) ($input['elisa_batch_id'] ?? '')),
                ),
                'reagent' => array(
                    'target_name' => trim((string) ($input['elisa_target_name'] ?? '')),
                    'kit_name' => trim((string) ($input['elisa_kit_name'] ?? '')),
                    'kit_object' => $this->readReagentObjectRef($elisaKitObjectId),
                    'capture_antibody' => trim((string) ($input['elisa_capture_antibody'] ?? '')),
                    'capture_object' => $this->readAntibodyObjectRef($elisaCaptureObjectId),
                    'detection_antibody' => trim((string) ($input['elisa_detection_antibody'] ?? '')),
                    'detection_object' => $this->readAntibodyObjectRef($elisaDetectionObjectId),
                    'substrate_name' => trim((string) ($input['elisa_substrate_name'] ?? '')),
                    'substrate_object' => $this->readReagentObjectRef($elisaSubstrateObjectId),
                ),
                'run' => array(
                    'plate_layout' => trim((string) ($input['elisa_plate_layout'] ?? '')),
                    'incubation_condition' => trim((string) ($input['elisa_incubation_condition'] ?? '')),
                    'wash_cycles' => trim((string) ($input['elisa_wash_cycles'] ?? '')),
                    'reader_model' => trim((string) ($input['elisa_reader_model'] ?? '')),
                    'reader_object' => $this->readReagentObjectRef($elisaReaderObjectId),
                    'wavelength_nm' => trim((string) ($input['elisa_wavelength_nm'] ?? '')),
                    'mode' => $packMode,
                ),
                'analysis' => array(
                    'standard_curve_r2' => trim((string) ($input['elisa_standard_curve_r2'] ?? '')),
                    'blank_od450' => trim((string) ($input['elisa_blank_od450'] ?? '')),
                    'cv_threshold' => trim((string) ($input['elisa_cv_threshold'] ?? '')),
                    'conclusion' => trim((string) ($input['elisa_conclusion'] ?? '')),
                ),
                'results' => $this->normalizeGenericRows(
                    labels: $input['elisa_result_sample_label'] ?? array(),
                    metrics: array(
                        'group_label' => $input['elisa_result_group_label'] ?? array(),
                        'od450' => $input['elisa_result_od450'] ?? array(),
                        'concentration' => $input['elisa_result_concentration'] ?? array(),
                        'cv_percent' => $input['elisa_result_cv_percent'] ?? array(),
                        'note' => $input['elisa_result_note'] ?? array(),
                    ),
                    minimumRows: 4,
                ),
                'qc' => array(
                    'blank_control_ok' => trim((string) ($input['elisa_blank_control_ok'] ?? 'yes')),
                    'standard_curve_ok' => trim((string) ($input['elisa_standard_curve_ok'] ?? 'yes')),
                    'replicate_cv_ok' => trim((string) ($input['elisa_replicate_cv_ok'] ?? 'yes')),
                    'notes' => trim((string) ($input['elisa_qc_notes'] ?? '')),
                ),
            ),
            default => array(
                'cover' => $cover,
                'sample' => array(
                    'sample_origin' => trim((string) ($input['qpcr_sample_origin'] ?? '')),
                    'treatment_group' => trim((string) ($input['qpcr_treatment_group'] ?? '')),
                    'rna_concentration' => trim((string) ($input['qpcr_rna_concentration'] ?? '')),
                    'a260_a280' => trim((string) ($input['qpcr_a260_a280'] ?? '')),
                    'reverse_transcription_batch' => trim((string) ($input['qpcr_rt_batch'] ?? '')),
                ),
                'primer' => array(
                    'target_gene' => trim((string) ($input['qpcr_target_gene'] ?? '')),
                    'reference_gene' => trim((string) ($input['qpcr_reference_gene'] ?? '')),
                    'primer_code' => trim((string) ($input['qpcr_primer_code'] ?? '')),
                    'amplicon_bp' => trim((string) ($input['qpcr_amplicon_bp'] ?? '')),
                    'annealing_temperature' => trim((string) ($input['qpcr_annealing_temperature'] ?? '')),
                    'object' => $this->readPrimerObjectRef($qpcrPrimerObjectId),
                ),
                'setup' => array(
                    'kit_name' => trim((string) ($input['qpcr_kit_name'] ?? '')),
                    'kit_object' => $this->readReagentObjectRef($qpcrKitObjectId),
                    'reaction_volume' => trim((string) ($input['qpcr_reaction_volume'] ?? '')),
                    'cycler_program' => trim((string) ($input['qpcr_cycler_program'] ?? '')),
                    'instrument_model' => trim((string) ($input['qpcr_instrument_model'] ?? '')),
                    'instrument_object' => $this->readReagentObjectRef($qpcrInstrumentObjectId),
                    'mode' => $packMode,
                ),
                'qc' => array(
                    'negative_control_amplified' => trim((string) ($input['qpcr_negative_control_amplified'] ?? 'no')),
                    'melting_curve_single_peak' => trim((string) ($input['qpcr_melting_curve_single_peak'] ?? 'yes')),
                    'notes' => trim((string) ($input['qpcr_qc_notes'] ?? '')),
                ),
            ),
        };
        return $this->applyAssayCoverDefaults($packKey, $payload);
    }

    private function applyAssayCoverDefaults(string $packKey, array $payload): array
    {
        $cover = is_array($payload['cover'] ?? null) ? $payload['cover'] : array();
        $cover['experiment_type'] = trim((string) ($cover['experiment_type'] ?? '')) !== ''
            ? (string) $cover['experiment_type']
            : $this->getAssayPackDisplayLabel($packKey);
        $cover['objective'] = trim((string) ($cover['objective'] ?? '')) !== ''
            ? trim((string) $cover['objective'])
            : $this->getAssayDefaultObjective($packKey);
        $cover['operator_name'] = trim((string) ($cover['operator_name'] ?? '')) !== ''
            ? trim((string) $cover['operator_name'])
            : $this->getCurrentUserFullname();
        $cover['run_date'] = trim((string) ($cover['run_date'] ?? '')) !== ''
            ? trim((string) $cover['run_date'])
            : date('Y-m-d');
        $cover['project_code'] = trim((string) ($cover['project_code'] ?? ''));
        $cover['sample_source'] = trim((string) ($cover['sample_source'] ?? ''));
        $cover['linked_sample_id'] = (int) ($cover['linked_sample_id'] ?? 0);
        $cover['linked_lot_id'] = (int) ($cover['linked_lot_id'] ?? 0);
        $payload['cover'] = $cover;
        return $payload;
    }

    private function getCurrentUserFullname(): string
    {
        return trim(sprintf('%s %s', $this->Users->userData['firstname'] ?? '', $this->Users->userData['lastname'] ?? ''));
    }

    private function getAssayDefaultObjective(string $packKey): string
    {
        $presetCatalog = $this->getAssayPresetCatalog();
        $objectives = $presetCatalog[$packKey]['objectives'] ?? $presetCatalog['qpcr']['objectives'];
        return (string) ($objectives[0] ?? '');
    }

    private function normalizeQpcrResults(array $input): array
    {
        $sampleLabels = $this->normalizeStringArray($input['qpcr_result_sample_label'] ?? array());
        $groupLabels = $this->normalizeStringArray($input['qpcr_result_group_label'] ?? array());
        $targetGenes = $this->normalizeStringArray($input['qpcr_result_target_gene'] ?? array());
        $ctValues = $this->normalizeNumberArray($input['qpcr_result_ct_value'] ?? array());
        $referenceCts = $this->normalizeNumberArray($input['qpcr_result_reference_ct'] ?? array());
        $deltaCts = $this->normalizeNumberArray($input['qpcr_result_delta_ct'] ?? array());
        $deltaDeltaCts = $this->normalizeNumberArray($input['qpcr_result_delta_delta_ct'] ?? array());
        $foldChanges = $this->normalizeNumberArray($input['qpcr_result_fold_change'] ?? array());
        $replicateCounts = $this->normalizeIntArray($input['qpcr_result_replicate_count'] ?? array());
        $outlierRows = $this->normalizeStringArray($input['qpcr_result_is_outlier'] ?? array());
        $notes = $this->normalizeStringArray($input['qpcr_result_note'] ?? array());

        $maxRows = max(
            count($sampleLabels),
            count($groupLabels),
            count($targetGenes),
            count($ctValues),
            count($referenceCts),
            count($deltaCts),
            count($deltaDeltaCts),
            count($foldChanges),
            count($replicateCounts),
            count($outlierRows),
            count($notes),
            4,
        );

        $rows = array();
        for ($i = 0; $i < $maxRows; $i++) {
            $sampleLabel = $sampleLabels[$i] ?? '';
            $groupLabel = $groupLabels[$i] ?? '';
            $targetGene = $targetGenes[$i] ?? '';
            $ctValue = $ctValues[$i] ?? null;
            $referenceCt = $referenceCts[$i] ?? null;
            $deltaCt = $deltaCts[$i] ?? null;
            $deltaDeltaCt = $deltaDeltaCts[$i] ?? null;
            $foldChange = $foldChanges[$i] ?? null;
            $replicateCount = $replicateCounts[$i] ?? null;
            $note = $notes[$i] ?? '';

            if ($deltaCt === null && $ctValue !== null && $referenceCt !== null) {
                $deltaCt = round($ctValue - $referenceCt, 4);
            }
            if ($foldChange === null && $deltaDeltaCt !== null) {
                $foldChange = round(pow(2, -1 * $deltaDeltaCt), 6);
            }

            if ($sampleLabel === '' && $groupLabel === '' && $targetGene === '' && $ctValue === null && $referenceCt === null && $deltaCt === null && $deltaDeltaCt === null && $foldChange === null && $replicateCount === null && $note === '') {
                continue;
            }

            $rows[] = array(
                'sample_label' => $sampleLabel,
                'group_label' => $groupLabel,
                'target_gene' => $targetGene,
                'ct_value' => $ctValue,
                'reference_ct' => $referenceCt,
                'delta_ct' => $deltaCt,
                'delta_delta_ct' => $deltaDeltaCt,
                'fold_change' => $foldChange,
                'replicate_count' => $replicateCount,
                'is_outlier' => (($outlierRows[$i] ?? '') === '1') ? 1 : 0,
                'note' => $note,
            );
        }

        return $rows === array() ? $this->getDefaultQpcrResults() : $rows;
    }

    private function buildQpcrQcSummary(array $payload, array $results): array
    {
        $feedback = array();
        if (($payload['qc']['negative_control_amplified'] ?? 'no') === 'yes') {
            $feedback[] = '阴性对照出现扩增，需先排查污染或引物特异性。';
        }
        if (($payload['qc']['melting_curve_single_peak'] ?? 'yes') !== 'yes') {
            $feedback[] = '熔解曲线不是单峰，结果特异性不足。';
        }

        $outlierCount = 0;
        $lowReplicateCount = 0;
        foreach ($results as $row) {
            if ((int) ($row['is_outlier'] ?? 0) === 1) {
                $outlierCount++;
            }
            if (($row['replicate_count'] ?? null) !== null && (int) $row['replicate_count'] < 2) {
                $lowReplicateCount++;
            }
        }

        if ($outlierCount > 0) {
            $feedback[] = sprintf('结果表中有 %d 行被标记为异常孔/异常重复。', $outlierCount);
        }
        if ($lowReplicateCount > 0) {
            $feedback[] = sprintf('有 %d 行重复数小于 2，重复孔支持不足。', $lowReplicateCount);
        }

        $status = $feedback === array() ? 'pass' : 'attention';

        return array(
            'status' => $status,
            'feedback' => $feedback === array()
                ? array('当前 qPCR 结果未触发明显质控警报，可进入结果解读。')
                : $feedback,
        );
    }

    private function buildQpcrAutoSummary(array $payload, array $results, array $qcSummary): string
    {
        $increased = 0;
        $decreased = 0;
        $stable = 0;
        foreach ($results as $row) {
            $foldChange = $row['fold_change'];
            if ($foldChange === null) {
                continue;
            }
            if ($foldChange >= 1.5) {
                $increased++;
            } elseif ($foldChange <= 0.67) {
                $decreased++;
            } else {
                $stable++;
            }
        }

        $parts = array();
        $targetGene = trim((string) ($payload['primer']['target_gene'] ?? ''));
        $referenceGene = trim((string) ($payload['primer']['reference_gene'] ?? ''));
        if ($targetGene !== '') {
            $parts[] = sprintf('本次 qPCR 记录围绕 %s 展开', $targetGene);
        } else {
            $parts[] = '本次 qPCR 记录已按结构化结果表归档';
        }
        if ($referenceGene !== '') {
            $parts[] = sprintf('以内参 %s 做归一化', $referenceGene);
        }
        if (($payload['cover']['linked_sample_id'] ?? 0) > 0) {
            $parts[] = sprintf('已关联主样本 #%d', (int) $payload['cover']['linked_sample_id']);
        }
        $parts[] = sprintf('共记录 %d 行结果', count($results));
        if ($increased > 0 || $decreased > 0 || $stable > 0) {
            $parts[] = sprintf('其中上调 %d 行、下调 %d 行、接近稳定 %d 行', $increased, $decreased, $stable);
        }
        if (($qcSummary['status'] ?? 'draft') === 'pass') {
            $parts[] = '当前质控检查通过';
        } else {
            $parts[] = '当前质控提示需要人工复核';
        }

        return implode('。', $parts) . '。';
    }

    private function buildGenericQcSummary(string $packKey, array $payload): array
    {
        $feedback = array();
        foreach (($payload['qc'] ?? array()) as $field => $value) {
            if ($field === 'notes' || $value !== 'no') {
                continue;
            }
            $feedback[] = match ($field) {
                'transfer_uniform' => '转膜均一性未通过，建议复核转膜条件和膜面完整性。',
                'background_clean' => '背景偏高，建议调整封闭或曝光条件。',
                'replicate_consistent' => '重复间一致性不足，建议增加重复或重做关键样本。',
                'negative_control_ok' => '阴性对照不理想，需复核抗体特异性或背景来源。',
                'background_ok' => $packKey === 'ihc'
                    ? 'IHC 背景控制不理想，建议复核封闭和显色时间。'
                    : '背景控制不理想，建议复核染色和洗涤条件。',
                'exposure_consistent' => '曝光参数不一致，定量可比性不足。',
                'positive_control_ok' => '阳性对照未达预期，评分前需复核染色批次。',
                'scoring_complete' => '评分字段尚未完整，病理结论需要补充。',
                'blank_control_ok' => '空白孔背景不理想，建议复核洗板、底物和终止条件。',
                'standard_curve_ok' => '标准曲线不理想，建议复核标准品梯度和拟合结果。',
                'replicate_cv_ok' => '重复孔 CV 偏高，建议检查加样一致性并复测关键样本。',
                default => '存在需要人工复核的质控项。',
            };
        }

        $resultRows = $this->getFilledGenericResultRows($payload);
        if ($resultRows === array()) {
            $feedback[] = '结构化结果表仍为空，当前记录还不足以形成标准化结论。';
        } else {
            $incompleteRows = 0;
            foreach ($resultRows as $row) {
                if ($packKey === 'western_blot') {
                    if (trim((string) ($row['normalized_signal'] ?? '')) === '' || trim((string) ($row['trend'] ?? '')) === '') {
                        $incompleteRows++;
                    }
                } elseif ($packKey === 'if') {
                    if (trim((string) ($row['positive_ratio'] ?? '')) === '' || trim((string) ($row['mean_intensity'] ?? '')) === '') {
                        $incompleteRows++;
                    }
                } elseif ($packKey === 'ihc') {
                    if (trim((string) ($row['h_score'] ?? '')) === '' && trim((string) ($row['irs_score'] ?? '')) === '') {
                        $incompleteRows++;
                    }
                } elseif ($packKey === 'elisa') {
                    if (trim((string) ($row['od450'] ?? '')) === '' || trim((string) ($row['concentration'] ?? '')) === '') {
                        $incompleteRows++;
                    }
                }
            }

            if ($incompleteRows > 0) {
                $feedback[] = sprintf('结构化结果表中有 %d 行关键字段未填完整，建议补齐后再归档结论。', $incompleteRows);
            }

            if ($packKey === 'if') {
                $expectedFieldCount = (int) trim((string) ($payload['analysis']['field_count'] ?? '0'));
                if ($expectedFieldCount > 0 && count($resultRows) < $expectedFieldCount) {
                    $feedback[] = sprintf('已声明 %d 个视野，但结果表仅记录 %d 行，建议核对视野采集是否完整。', $expectedFieldCount, count($resultRows));
                }
            }

            if ($packKey === 'ihc') {
                $highScoreRows = 0;
                foreach ($resultRows as $row) {
                    $hScore = (float) trim((string) ($row['h_score'] ?? '0'));
                    $irsScore = (float) trim((string) ($row['irs_score'] ?? '0'));
                    if ($hScore >= 200 || $irsScore >= 8) {
                        $highScoreRows++;
                    }
                }
                if ($highScoreRows > 0 && (($payload['analysis']['localization'] ?? '') === '')) {
                    $feedback[] = '存在高评分区域，但定位描述为空，建议补充阳性定位信息。';
                }
            }

            if ($packKey === 'elisa') {
                $curveR2 = (float) trim((string) ($payload['analysis']['standard_curve_r2'] ?? '0'));
                if ($curveR2 > 0 && $curveR2 < 0.99) {
                    $feedback[] = '标准曲线 R² 低于 0.99，建议复核标准品梯度和读板结果。';
                }
                $highCvRows = 0;
                foreach ($resultRows as $row) {
                    $cv = (float) trim((string) ($row['cv_percent'] ?? '0'));
                    if ($cv > 15) {
                        $highCvRows++;
                    }
                }
                if ($highCvRows > 0) {
                    $feedback[] = sprintf('有 %d 行样本重复孔 CV 超过 15%%，建议复测。', $highCvRows);
                }
            }
        }

        return array(
            'status' => $feedback === array() ? 'pass' : 'attention',
            'feedback' => $feedback === array()
                ? array(sprintf('%s 当前未触发明显质控警报，可继续整理结果。', $this->getAssayPackDisplayLabel($packKey)))
                : $feedback,
        );
    }

    private function buildGenericAutoSummary(string $packKey, array $payload, array $qcSummary): string
    {
        $parts = array(
            sprintf('本次 %s 已按结构化记录归档', $this->getAssayPackDisplayLabel($packKey)),
        );

        $objective = trim((string) ($payload['cover']['objective'] ?? ''));
        if ($objective !== '') {
            $parts[] = sprintf('实验目标为%s', $objective);
        }

        $conclusion = trim((string) ($payload['analysis']['conclusion'] ?? ''));
        if ($conclusion !== '') {
            $parts[] = sprintf('当前结论为%s', $conclusion);
        }
        $resultRows = $this->getFilledGenericResultRows($payload);
        $rowCount = count($resultRows);
        if ($rowCount > 0) {
            $parts[] = sprintf('共整理 %d 行结构化结果', $rowCount);
        }
        if ($packKey === 'western_blot' && $rowCount > 0) {
            $up = 0;
            $down = 0;
            $stable = 0;
            foreach ($resultRows as $row) {
                $trend = trim((string) ($row['trend'] ?? ''));
                if ($trend === 'up') {
                    $up++;
                } elseif ($trend === 'down') {
                    $down++;
                } elseif ($trend !== '') {
                    $stable++;
                }
            }
            if ($up > 0 || $down > 0 || $stable > 0) {
                $parts[] = sprintf('条带趋势统计为上调 %d 行、下调 %d 行、稳定 %d 行', $up, $down, $stable);
            }
        }
        if ($packKey === 'if' && $rowCount > 0) {
            $quantifiedRows = 0;
            foreach ($resultRows as $row) {
                if (trim((string) ($row['positive_ratio'] ?? '')) !== '' || trim((string) ($row['mean_intensity'] ?? '')) !== '') {
                    $quantifiedRows++;
                }
            }
            $parts[] = sprintf('其中 %d 个视野已完成阳性比例或荧光强度定量', $quantifiedRows);
        }
        if ($packKey === 'ihc' && $rowCount > 0) {
            $scoredRows = 0;
            foreach ($resultRows as $row) {
                if (trim((string) ($row['h_score'] ?? '')) !== '' || trim((string) ($row['irs_score'] ?? '')) !== '') {
                    $scoredRows++;
                }
            }
            $parts[] = sprintf('其中 %d 个区域已完成病理评分', $scoredRows);
        }
        if ($packKey === 'elisa' && $rowCount > 0) {
            $quantifiedRows = 0;
            foreach ($resultRows as $row) {
                if (trim((string) ($row['concentration'] ?? '')) !== '') {
                    $quantifiedRows++;
                }
            }
            $parts[] = sprintf('其中 %d 个样本已完成浓度回算', $quantifiedRows);
        }
        if (($payload['cover']['linked_sample_id'] ?? 0) > 0) {
            $parts[] = sprintf('已关联主样本 #%d', (int) $payload['cover']['linked_sample_id']);
        }

        $parts[] = ($qcSummary['status'] ?? 'draft') === 'pass'
            ? '当前质控检查通过'
            : '当前质控提示需要人工复核';

        return implode('。', $parts) . '。';
    }

    private function collectPackObjectCodes(string $packKey, array $payload): array
    {
        $codes = match ($packKey) {
            'western_blot' => array(
                (string) ($payload['reagent']['primary_object']['code'] ?? ''),
                (string) ($payload['reagent']['secondary_object']['code'] ?? ''),
                (string) ($payload['reagent']['detection_object']['code'] ?? ''),
                (string) ($payload['reagent']['membrane_object']['code'] ?? ''),
            ),
            'if' => array(
                (string) ($payload['staining']['primary_object']['code'] ?? ''),
                (string) ($payload['staining']['secondary_object']['code'] ?? ''),
                (string) ($payload['staining']['blocking_object']['code'] ?? ''),
                (string) ($payload['imaging']['microscope_object']['code'] ?? ''),
            ),
            'ihc' => array(
                (string) ($payload['staining']['primary_object']['code'] ?? ''),
                (string) ($payload['staining']['secondary_object']['code'] ?? ''),
                (string) ($payload['sample']['fixative_object']['code'] ?? ''),
                (string) ($payload['staining']['antigen_retrieval_object']['code'] ?? ''),
                (string) ($payload['staining']['counterstain_object']['code'] ?? ''),
            ),
            'elisa' => array(
                (string) ($payload['reagent']['capture_object']['code'] ?? ''),
                (string) ($payload['reagent']['detection_object']['code'] ?? ''),
                (string) ($payload['reagent']['kit_object']['code'] ?? ''),
                (string) ($payload['reagent']['substrate_object']['code'] ?? ''),
                (string) ($payload['run']['reader_object']['code'] ?? ''),
            ),
            default => array(),
        };

        return array_values(array_filter($codes, static fn(string $code): bool => $code !== ''));
    }

    private function normalizeStringArray(mixed $values): array
    {
        if (!is_array($values)) {
            return array();
        }

        return array_map(
            static fn(mixed $value): string => trim((string) $value),
            $values,
        );
    }

    private function normalizeNumberArray(mixed $values): array
    {
        if (!is_array($values)) {
            return array();
        }

        return array_map(
            static function (mixed $value): ?float {
                $string = trim((string) $value);
                if ($string === '') {
                    return null;
                }
                return (float) $string;
            },
            $values,
        );
    }

    private function normalizeIntArray(mixed $values): array
    {
        if (!is_array($values)) {
            return array();
        }

        return array_map(
            static function (mixed $value): ?int {
                $string = trim((string) $value);
                if ($string === '') {
                    return null;
                }
                return (int) $string;
            },
            $values,
        );
    }

    private function normalizeGenericRows(array $labels, array $metrics, int $minimumRows = 3): array
    {
        $normalizedLabels = $this->normalizeStringArray($labels);
        $normalizedMetrics = array();
        $maxRows = max(count($normalizedLabels), $minimumRows);
        foreach ($metrics as $key => $values) {
            $normalizedMetrics[$key] = $this->normalizeStringArray($values);
            $maxRows = max($maxRows, count($normalizedMetrics[$key]));
        }

        $rows = array();
        for ($i = 0; $i < $maxRows; $i++) {
            $row = array('label' => $normalizedLabels[$i] ?? '');
            $hasValue = $row['label'] !== '';
            foreach (array_keys($metrics) as $key) {
                $value = $normalizedMetrics[$key][$i] ?? '';
                $row[$key] = $value;
                if ($value !== '') {
                    $hasValue = true;
                }
            }

            if (!$hasValue) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows === array() ? $this->getDefaultGenericRows(array_keys($metrics), $minimumRows) : $rows;
    }

    private function getDefaultAssayPayload(string $packKey = 'qpcr'): array
    {
        $cover = array(
            'experiment_type' => $this->getAssayPackDisplayLabel($packKey),
            'objective' => '',
            'project_code' => '',
            'operator_name' => '',
            'sample_source' => '',
            'run_date' => '',
            'linked_sample_id' => 0,
            'linked_lot_id' => 0,
        );

        $payload = match ($packKey) {
            'western_blot' => array(
                'cover' => $cover,
                'sample' => array(
                    'sample_origin' => '',
                    'loading_amount' => '',
                    'denaturation_condition' => '',
                ),
                'run' => array(
                    'gel_percentage' => '',
                    'electrophoresis_condition' => '',
                    'transfer_condition' => '',
                    'membrane_type' => '',
                    'mode' => 'quick',
                ),
                'reagent' => array(
                    'primary_antibody' => '',
                    'primary_dilution' => '',
                    'primary_object' => $this->getEmptyObjectRef(),
                    'secondary_antibody' => '',
                    'secondary_dilution' => '',
                    'secondary_object' => $this->getEmptyObjectRef(),
                    'detection_reagent' => '',
                    'detection_object' => $this->getEmptyObjectRef(),
                    'membrane_object' => $this->getEmptyObjectRef(),
                ),
                'analysis' => array(
                    'target_band_kda' => '',
                    'reference_control' => '',
                    'normalization_method' => '',
                    'conclusion' => '',
                ),
                'results' => $this->getDefaultGenericRows(
                    array('target_signal', 'reference_signal', 'normalized_signal', 'trend', 'note'),
                    3,
                ),
                'image' => array(
                    'raw_image_ref' => '',
                    'cropped_image_ref' => '',
                    'annotation_note' => '',
                ),
                'qc' => array(
                    'transfer_uniform' => 'yes',
                    'background_clean' => 'yes',
                    'replicate_consistent' => 'yes',
                    'notes' => '',
                ),
            ),
            'if' => array(
                'cover' => $cover,
                'sample' => array(
                    'sample_origin' => '',
                    'seeding_density' => '',
                    'treatment_condition' => '',
                    'fixation_method' => '',
                ),
                'staining' => array(
                    'blocking_buffer' => '',
                    'permeabilization' => '',
                    'primary_antibody' => '',
                    'primary_object' => $this->getEmptyObjectRef(),
                    'secondary_antibody' => '',
                    'secondary_object' => $this->getEmptyObjectRef(),
                    'dapi' => '',
                    'dark_incubation' => '',
                    'blocking_object' => $this->getEmptyObjectRef(),
                ),
                'imaging' => array(
                    'microscope' => '',
                    'objective' => '',
                    'excitation' => '',
                    'exposure' => '',
                    'batch' => '',
                    'microscope_object' => $this->getEmptyObjectRef(),
                    'mode' => 'quick',
                ),
                'analysis' => array(
                    'field_count' => '',
                    'positive_ratio' => '',
                    'mean_intensity' => '',
                    'colocalization' => '',
                    'conclusion' => '',
                ),
                'results' => $this->getDefaultGenericRows(
                    array('cell_count', 'positive_ratio', 'mean_intensity', 'colocalization', 'note'),
                    3,
                ),
                'image' => array(
                    'raw_image_ref' => '',
                    'merged_image_ref' => '',
                    'annotation_note' => '',
                ),
                'qc' => array(
                    'negative_control_ok' => 'yes',
                    'background_ok' => 'yes',
                    'exposure_consistent' => 'yes',
                    'notes' => '',
                ),
            ),
            'ihc' => array(
                'cover' => $cover,
                'sample' => array(
                    'tissue_id' => '',
                    'fixative' => '',
                    'embedding_batch' => '',
                    'section_thickness' => '',
                    'fixative_object' => $this->getEmptyObjectRef(),
                ),
                'staining' => array(
                    'antigen_retrieval' => '',
                    'antigen_retrieval_object' => $this->getEmptyObjectRef(),
                    'blocking' => '',
                    'primary_antibody' => '',
                    'primary_object' => $this->getEmptyObjectRef(),
                    'secondary_antibody' => '',
                    'secondary_object' => $this->getEmptyObjectRef(),
                    'dab_time' => '',
                    'counterstain' => '',
                    'counterstain_object' => $this->getEmptyObjectRef(),
                ),
                'analysis' => array(
                    'localization' => '',
                    'positive_area' => '',
                    'staining_intensity' => '',
                    'h_score' => '',
                    'conclusion' => '',
                ),
                'results' => $this->getDefaultGenericRows(
                    array('positive_ratio', 'intensity_score', 'h_score', 'irs_score', 'note'),
                    3,
                ),
                'image' => array(
                    'low_magnification_ref' => '',
                    'high_magnification_ref' => '',
                    'zoomed_region_ref' => '',
                    'analysis_images' => array(),
                ),
                'qc' => array(
                    'background_ok' => 'yes',
                    'positive_control_ok' => 'yes',
                    'scoring_complete' => 'yes',
                    'notes' => '',
                ),
            ),
            'elisa' => array(
                'cover' => $cover,
                'sample' => array(
                    'sample_origin' => '',
                    'sample_type' => '',
                    'dilution_factor' => '',
                    'batch_id' => '',
                ),
                'reagent' => array(
                    'target_name' => '',
                    'kit_name' => '',
                    'kit_object' => $this->getEmptyObjectRef(),
                    'capture_antibody' => '',
                    'capture_object' => $this->getEmptyObjectRef(),
                    'detection_antibody' => '',
                    'detection_object' => $this->getEmptyObjectRef(),
                    'substrate_name' => '',
                    'substrate_object' => $this->getEmptyObjectRef(),
                ),
                'run' => array(
                    'plate_layout' => '',
                    'incubation_condition' => '',
                    'wash_cycles' => '',
                    'reader_model' => '',
                    'reader_object' => $this->getEmptyObjectRef(),
                    'wavelength_nm' => '',
                    'mode' => 'quick',
                ),
                'analysis' => array(
                    'standard_curve_r2' => '',
                    'blank_od450' => '',
                    'cv_threshold' => '',
                    'conclusion' => '',
                ),
                'results' => $this->getDefaultGenericRows(
                    array('group_label', 'od450', 'concentration', 'cv_percent', 'note'),
                    4,
                ),
                'qc' => array(
                    'blank_control_ok' => 'yes',
                    'standard_curve_ok' => 'yes',
                    'replicate_cv_ok' => 'yes',
                    'notes' => '',
                ),
            ),
            default => array(
                'cover' => $cover,
                'sample' => array(
                    'sample_origin' => '',
                    'treatment_group' => '',
                    'rna_concentration' => '',
                    'a260_a280' => '',
                    'reverse_transcription_batch' => '',
                ),
                'primer' => array(
                    'target_gene' => '',
                    'reference_gene' => '',
                    'primer_code' => '',
                    'amplicon_bp' => '',
                    'annealing_temperature' => '',
                    'object' => $this->getEmptyObjectRef(),
                ),
                'setup' => array(
                    'kit_name' => '',
                    'kit_object' => $this->getEmptyObjectRef(),
                    'reaction_volume' => '',
                    'cycler_program' => '',
                    'instrument_model' => '',
                    'instrument_object' => $this->getEmptyObjectRef(),
                    'mode' => 'quick',
                ),
                'qc' => array(
                    'negative_control_amplified' => 'no',
                    'melting_curve_single_peak' => 'yes',
                    'notes' => '',
                ),
            ),
        };
        return $this->applyAssayCoverDefaults($packKey, $payload);
    }

    private function getSupportedAssayPackKeys(): array
    {
        return array('qpcr', 'western_blot', 'if', 'ihc', 'elisa');
    }

    private function getAssayPackDisplayLabel(string $packKey): string
    {
        return match ($packKey) {
            'western_blot' => 'Western Blot',
            'if' => 'Immunofluorescence',
            'ihc' => 'Immunohistochemistry',
            'elisa' => 'ELISA',
            default => 'qPCR',
        };
    }

    private function getFilledGenericResultRows(array $payload): array
    {
        $rows = array();
        foreach (($payload['results'] ?? array()) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $hasMetric = false;
            foreach ($row as $key => $value) {
                if ($key === 'label') {
                    continue;
                }
                if (trim((string) $value) !== '') {
                    $hasMetric = true;
                    break;
                }
            }
            if ($label === '' && !$hasMetric) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function buildAnalysisCards(string $packKey, array $payload, array $results, array $feedback): array
    {
        return match ($packKey) {
            'western_blot' => $this->buildWesternBlotAnalysisCards($payload, $feedback),
            'if' => $this->buildIfAnalysisCards($payload, $feedback),
            'ihc' => $this->buildIhcAnalysisCards($payload, $feedback),
            'elisa' => $this->buildElisaAnalysisCards($payload, $feedback),
            default => $this->buildQpcrAnalysisCards($payload, $results, $feedback),
        };
    }

    private function buildQpcrAnalysisCards(array $payload, array $results, array $feedback): array
    {
        $up = 0;
        $down = 0;
        $stable = 0;
        $bestFold = null;
        foreach ($results as $row) {
            $fold = $row['fold_change'] ?? null;
            if ($fold === null) {
                continue;
            }
            $bestFold = $bestFold === null ? (float) $fold : max($bestFold, (float) $fold);
            if ($fold >= 1.5) {
                $up++;
            } elseif ($fold <= 0.67) {
                $down++;
            } else {
                $stable++;
            }
        }

        return array(
            array('label' => '差异趋势', 'value' => sprintf('上调 %d / 下调 %d / 稳定 %d', $up, $down, $stable), 'tone' => 'info'),
            array('label' => '最高 Fold Change', 'value' => $bestFold === null ? '未形成' : (string) round($bestFold, 4), 'tone' => $bestFold !== null && $bestFold >= 2 ? 'success' : 'neutral'),
            array('label' => '分组覆盖', 'value' => sprintf('%d 个实验组已录入', count($this->buildQpcrGroupAnalysisRows($results))), 'tone' => count($this->buildQpcrGroupAnalysisRows($results)) > 0 ? 'info' : 'neutral'),
            array('label' => '分析判读', 'value' => $feedback === array() ? '可进入结果解释和汇总' : '先处理质控提示再提交结论', 'tone' => $feedback === array() ? 'success' : 'warning'),
        );
    }

    private function buildWesternBlotAnalysisCards(array $payload, array $feedback): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $up = 0;
        $down = 0;
        $stable = 0;
        foreach ($rows as $row) {
            $trend = trim((string) ($row['trend'] ?? ''));
            if ($trend === 'up') {
                $up++;
            } elseif ($trend === 'down') {
                $down++;
            } elseif ($trend !== '') {
                $stable++;
            }
        }

        return array(
            array('label' => '条带趋势', 'value' => sprintf('上调 %d / 下调 %d / 稳定 %d', $up, $down, $stable), 'tone' => 'info'),
            array('label' => '结果覆盖', 'value' => sprintf('%d 行已结构化', count($rows)), 'tone' => count($rows) > 0 ? 'success' : 'neutral'),
            array('label' => '分析判读', 'value' => $feedback === array() ? '可结合原图与灰度继续解释' : '先补齐关键信号或质控项', 'tone' => $feedback === array() ? 'success' : 'warning'),
        );
    }

    private function buildIfAnalysisCards(array $payload, array $feedback): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $quantified = 0;
        foreach ($rows as $row) {
            if (trim((string) ($row['positive_ratio'] ?? '')) !== '' || trim((string) ($row['mean_intensity'] ?? '')) !== '') {
                $quantified++;
            }
        }

        return array(
            array('label' => '视野定量', 'value' => sprintf('%d / %d 个视野已定量', $quantified, count($rows)), 'tone' => $quantified > 0 ? 'info' : 'neutral'),
            array('label' => '成像一致性', 'value' => (($payload['qc']['exposure_consistent'] ?? 'yes') === 'yes') ? '曝光参数一致' : '曝光需复核', 'tone' => (($payload['qc']['exposure_consistent'] ?? 'yes') === 'yes') ? 'success' : 'warning'),
            array('label' => '分析判读', 'value' => $feedback === array() ? '可比较阳性比例和荧光强度' : '先补齐视野结果或修正质控项', 'tone' => $feedback === array() ? 'success' : 'warning'),
        );
    }

    private function buildIhcAnalysisCards(array $payload, array $feedback): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $scored = 0;
        $highScore = 0;
        foreach ($rows as $row) {
            $hasScore = trim((string) ($row['h_score'] ?? '')) !== '' || trim((string) ($row['irs_score'] ?? '')) !== '';
            if ($hasScore) {
                $scored++;
            }
            if ((float) trim((string) ($row['h_score'] ?? '0')) >= 200 || (float) trim((string) ($row['irs_score'] ?? '0')) >= 8) {
                $highScore++;
            }
        }

        return array(
            array('label' => '病理评分', 'value' => sprintf('%d / %d 个区域已评分', $scored, count($rows)), 'tone' => $scored > 0 ? 'info' : 'neutral'),
            array('label' => '高表达区域', 'value' => $highScore > 0 ? sprintf('%d 个区域提示高表达', $highScore) : '未见明显高表达区域', 'tone' => $highScore > 0 ? 'warning' : 'success'),
            array('label' => '分析判读', 'value' => $feedback === array() ? '可整理病理定位和组间差异' : '先补评分或定位描述', 'tone' => $feedback === array() ? 'success' : 'warning'),
        );
    }

    private function buildElisaAnalysisCards(array $payload, array $feedback): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $quantified = 0;
        $highCv = 0;
        foreach ($rows as $row) {
            if (trim((string) ($row['concentration'] ?? '')) !== '') {
                $quantified++;
            }
            if ((float) trim((string) ($row['cv_percent'] ?? '0')) > 15) {
                $highCv++;
            }
        }

        return array(
            array('label' => '样本定量', 'value' => sprintf('%d / %d 个样本已回算浓度', $quantified, count($rows)), 'tone' => $quantified > 0 ? 'info' : 'neutral'),
            array('label' => '标准曲线', 'value' => (($payload['analysis']['standard_curve_r2'] ?? '') !== '') ? sprintf('R² = %s', (string) $payload['analysis']['standard_curve_r2']) : '未填写', 'tone' => ((float) ($payload['analysis']['standard_curve_r2'] ?? 0)) >= 0.99 ? 'success' : 'warning'),
            array('label' => '重复稳定性', 'value' => $highCv > 0 ? sprintf('%d 行 CV 偏高', $highCv) : '重复孔 CV 可接受', 'tone' => $highCv > 0 ? 'warning' : 'success'),
            array('label' => '分析判读', 'value' => $feedback === array() ? '可整理浓度差异和组间比较' : '先复核标准曲线或重复孔一致性', 'tone' => $feedback === array() ? 'success' : 'warning'),
        );
    }

    private function buildAnalysisRows(string $packKey, array $payload, array $results): array
    {
        return match ($packKey) {
            'western_blot' => $this->buildWesternBlotAnalysisRows($payload),
            'if' => $this->buildIfAnalysisRowsTable($payload),
            'ihc' => $this->buildIhcAnalysisRowsTable($payload),
            'elisa' => $this->buildElisaAnalysisRowsTable($payload),
            default => $this->buildQpcrAnalysisRows($results),
        };
    }

    private function buildAnalysisBars(string $packKey, array $payload, array $results): array
    {
        return match ($packKey) {
            'western_blot' => $this->buildWesternBlotAnalysisBars($payload),
            'if' => $this->buildIfAnalysisBars($payload),
            'ihc' => $this->buildIhcAnalysisBars($payload),
            'elisa' => $this->buildElisaAnalysisBars($payload),
            default => $this->buildQpcrAnalysisBars($results),
        };
    }

    private function buildElisaAnalysisRowsTable(array $payload): array
    {
        $rows = array();
        foreach ($this->getFilledGenericResultRows($payload) as $row) {
            $rows[] = array(
                'label' => (string) ($row['label'] ?? ''),
                'metric_a' => (string) ($row['od450'] ?? ''),
                'metric_b' => (string) ($row['concentration'] ?? ''),
                'direction' => (string) ($row['cv_percent'] ?? ''),
            );
        }
        return $rows;
    }

    private function buildElisaAnalysisBars(array $payload): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        if ($rows === array()) {
            return array();
        }

        $maxConc = 0.0;
        foreach ($rows as $row) {
            $maxConc = max($maxConc, (float) trim((string) ($row['concentration'] ?? '0')));
        }
        if ($maxConc <= 0) {
            return array();
        }

        $bars = array();
        foreach ($rows as $row) {
            $concentration = (float) trim((string) ($row['concentration'] ?? '0'));
            $cv = (float) trim((string) ($row['cv_percent'] ?? '0'));
            $bars[] = array(
                'label' => (string) ($row['label'] ?? ''),
                'value' => (string) ($row['concentration'] ?? ''),
                'percent' => max(6, min(100, round(($concentration / $maxConc) * 100))),
                'tone' => $cv > 15 ? 'warning' : 'success',
            );
        }
        return $bars;
    }

    private function buildQpcrAnalysisRows(array $results): array
    {
        $groups = array();
        foreach ($results as $row) {
            if ((int) ($row['is_outlier'] ?? 0) === 1) {
                continue;
            }
            $label = trim((string) ($row['sample_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            if (!isset($groups[$label])) {
                $groups[$label] = array(
                    'sample_label' => $label,
                    'rows' => 0,
                    'ct_values' => array(),
                    'fold_values' => array(),
                );
            }
            $groups[$label]['rows']++;
            if (($row['ct_value'] ?? null) !== null) {
                $groups[$label]['ct_values'][] = (float) $row['ct_value'];
            }
            if (($row['fold_change'] ?? null) !== null) {
                $groups[$label]['fold_values'][] = (float) $row['fold_change'];
            }
        }

        $summaryRows = array();
        foreach ($groups as $group) {
            $meanCt = $group['ct_values'] === array() ? null : array_sum($group['ct_values']) / count($group['ct_values']);
            $meanFold = $group['fold_values'] === array() ? null : array_sum($group['fold_values']) / count($group['fold_values']);
            $direction = '未形成';
            if ($meanFold !== null) {
                if ($meanFold >= 1.5) {
                    $direction = '上调';
                } elseif ($meanFold <= 0.67) {
                    $direction = '下调';
                } else {
                    $direction = '稳定';
                }
            }
            $summaryRows[] = array(
                'sample_label' => $group['sample_label'],
                'rows' => $group['rows'],
                'mean_ct' => $meanCt === null ? '' : round($meanCt, 4),
                'mean_fold' => $meanFold === null ? '' : round($meanFold, 4),
                'direction' => $direction,
            );
        }

        usort(
            $summaryRows,
            static function (array $a, array $b): int {
                return strcmp((string) $a['sample_label'], (string) $b['sample_label']);
            },
        );

        return $summaryRows;
    }

    private function buildQpcrGroupAnalysisRows(array $results): array
    {
        $groups = array();
        foreach ($results as $row) {
            if ((int) ($row['is_outlier'] ?? 0) === 1) {
                continue;
            }
            $label = trim((string) ($row['group_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            if (!isset($groups[$label])) {
                $groups[$label] = array(
                    'group_label' => $label,
                    'sample_count' => array(),
                    'fold_values' => array(),
                );
            }
            $sampleLabel = trim((string) ($row['sample_label'] ?? ''));
            if ($sampleLabel !== '') {
                $groups[$label]['sample_count'][$sampleLabel] = true;
            }
            if (($row['fold_change'] ?? null) !== null) {
                $groups[$label]['fold_values'][] = (float) $row['fold_change'];
            }
        }

        $summaryRows = array();
        foreach ($groups as $group) {
            $meanFold = $group['fold_values'] === array() ? null : array_sum($group['fold_values']) / count($group['fold_values']);
            $direction = '未形成';
            if ($meanFold !== null) {
                if ($meanFold >= 1.5) {
                    $direction = '上调';
                } elseif ($meanFold <= 0.67) {
                    $direction = '下调';
                } else {
                    $direction = '稳定';
                }
            }
            $summaryRows[] = array(
                'group_label' => $group['group_label'],
                'sample_count' => count($group['sample_count']),
                'mean_fold' => $meanFold === null ? '' : round($meanFold, 4),
                'direction' => $direction,
            );
        }
        usort(
            $summaryRows,
            static fn(array $a, array $b): int => strcmp((string) $a['group_label'], (string) $b['group_label']),
        );
        return $summaryRows;
    }

    private function buildQpcrAnalysisBars(array $results): array
    {
        $rows = $this->buildQpcrAnalysisRows($results);
        if ($rows === array()) {
            return array();
        }

        $maxFold = 0.0;
        foreach ($rows as $row) {
            $maxFold = max($maxFold, (float) ($row['mean_fold'] === '' ? 0 : $row['mean_fold']));
        }
        if ($maxFold <= 0) {
            $maxFold = 1.0;
        }

        $bars = array();
        foreach ($rows as $row) {
            $fold = (float) ($row['mean_fold'] === '' ? 0 : $row['mean_fold']);
            $bars[] = array(
                'label' => (string) $row['sample_label'],
                'value' => $row['mean_fold'] === '' ? '未形成' : (string) $row['mean_fold'],
                'percent' => (int) round(min(100, ($fold / $maxFold) * 100)),
                'tone' => $row['direction'] === '上调' ? 'success' : ($row['direction'] === '下调' ? 'warning' : 'neutral'),
            );
        }

        return $bars;
    }

    private function buildQpcrGroupAnalysisBars(array $results): array
    {
        $rows = $this->buildQpcrGroupAnalysisRows($results);
        if ($rows === array()) {
            return array();
        }

        $maxFold = 0.0;
        foreach ($rows as $row) {
            $maxFold = max($maxFold, (float) ($row['mean_fold'] === '' ? 0 : $row['mean_fold']));
        }
        if ($maxFold <= 0) {
            $maxFold = 1.0;
        }

        $bars = array();
        foreach ($rows as $row) {
            $fold = (float) ($row['mean_fold'] === '' ? 0 : $row['mean_fold']);
            $bars[] = array(
                'label' => (string) $row['group_label'],
                'value' => $row['mean_fold'] === '' ? '未形成' : (string) $row['mean_fold'],
                'percent' => (int) round(min(100, ($fold / $maxFold) * 100)),
                'tone' => $row['direction'] === '上调' ? 'success' : ($row['direction'] === '下调' ? 'warning' : 'neutral'),
            );
        }
        return $bars;
    }

    private function buildWesternBlotAnalysisRows(array $payload): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $summaryRows = array();
        foreach ($rows as $row) {
            $summaryRows[] = array(
                'label' => (string) ($row['label'] ?? ''),
                'metric_a' => trim((string) ($row['target_signal'] ?? '')) ?: '未填',
                'metric_b' => trim((string) ($row['normalized_signal'] ?? '')) ?: '未填',
                'direction' => trim((string) ($row['trend'] ?? '')) ?: '未判读',
            );
        }
        return $summaryRows;
    }

    private function buildWesternBlotAnalysisBars(array $payload): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $maxValue = 0.0;
        foreach ($rows as $row) {
            $value = (float) trim((string) ($row['normalized_signal'] ?? '0'));
            $maxValue = max($maxValue, $value);
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }

        $bars = array();
        foreach ($rows as $row) {
            $value = (float) trim((string) ($row['normalized_signal'] ?? '0'));
            $trend = trim((string) ($row['trend'] ?? ''));
            $bars[] = array(
                'label' => (string) ($row['label'] ?? '未命名样本'),
                'value' => trim((string) ($row['normalized_signal'] ?? '')) ?: '未填',
                'percent' => (int) round(min(100, ($value / $maxValue) * 100)),
                'tone' => $trend === 'up' ? 'success' : ($trend === 'down' ? 'warning' : 'neutral'),
            );
        }
        return $bars;
    }

    private function buildIfAnalysisRowsTable(array $payload): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $summaryRows = array();
        foreach ($rows as $row) {
            $summaryRows[] = array(
                'label' => (string) ($row['label'] ?? ''),
                'metric_a' => trim((string) ($row['positive_ratio'] ?? '')) ?: '未填',
                'metric_b' => trim((string) ($row['mean_intensity'] ?? '')) ?: '未填',
                'direction' => trim((string) ($row['colocalization'] ?? '')) ?: '未填',
            );
        }
        return $summaryRows;
    }

    private function buildIfAnalysisBars(array $payload): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $maxValue = 0.0;
        foreach ($rows as $row) {
            $value = (float) trim((string) ($row['positive_ratio'] ?? '0'));
            $maxValue = max($maxValue, $value);
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }

        $bars = array();
        foreach ($rows as $row) {
            $value = (float) trim((string) ($row['positive_ratio'] ?? '0'));
            $bars[] = array(
                'label' => (string) ($row['label'] ?? '未命名视野'),
                'value' => trim((string) ($row['positive_ratio'] ?? '')) ?: '未填',
                'percent' => (int) round(min(100, ($value / $maxValue) * 100)),
                'tone' => $value >= 50 ? 'success' : ($value > 0 ? 'warning' : 'neutral'),
            );
        }
        return $bars;
    }

    private function buildIhcAnalysisRowsTable(array $payload): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $summaryRows = array();
        foreach ($rows as $row) {
            $hScore = trim((string) ($row['h_score'] ?? ''));
            $irsScore = trim((string) ($row['irs_score'] ?? ''));
            $direction = '未评分';
            if ($hScore !== '' || $irsScore !== '') {
                $numeric = max((float) ($hScore === '' ? '0' : $hScore), (float) ($irsScore === '' ? '0' : $irsScore));
                $direction = $numeric >= 200 || $numeric >= 8 ? '高表达' : '低/中表达';
            }
            $summaryRows[] = array(
                'label' => (string) ($row['label'] ?? ''),
                'metric_a' => trim((string) ($row['h_score'] ?? '')) ?: '未填',
                'metric_b' => trim((string) ($row['irs_score'] ?? '')) ?: '未填',
                'direction' => $direction,
            );
        }
        return $summaryRows;
    }

    private function buildIhcAnalysisBars(array $payload): array
    {
        $rows = $this->getFilledGenericResultRows($payload);
        $maxValue = 0.0;
        foreach ($rows as $row) {
            $value = max((float) trim((string) ($row['h_score'] ?? '0')), (float) trim((string) ($row['irs_score'] ?? '0')));
            $maxValue = max($maxValue, $value);
        }
        if ($maxValue <= 0) {
            $maxValue = 1.0;
        }

        $bars = array();
        foreach ($rows as $row) {
            $hScore = (float) trim((string) ($row['h_score'] ?? '0'));
            $irsScore = (float) trim((string) ($row['irs_score'] ?? '0'));
            $value = max($hScore, $irsScore);
            $bars[] = array(
                'label' => (string) ($row['label'] ?? '未命名区域'),
                'value' => $value > 0 ? (string) round($value, 2) : '未填',
                'percent' => (int) round(min(100, ($value / $maxValue) * 100)),
                'tone' => $value >= 200 || $value >= 8 ? 'warning' : ($value > 0 ? 'success' : 'neutral'),
            );
        }
        return $bars;
    }

    private function getDefaultGenericRows(array $keys, int $rowCount): array
    {
        $rows = array();
        for ($i = 0; $i < $rowCount; $i++) {
            $row = array('label' => '');
            foreach ($keys as $key) {
                $row[$key] = '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function getDefaultQpcrResults(): array
    {
        return array(
            array('sample_label' => '', 'group_label' => '', 'target_gene' => '', 'ct_value' => null, 'reference_ct' => null, 'delta_ct' => null, 'delta_delta_ct' => null, 'fold_change' => null, 'replicate_count' => null, 'is_outlier' => 0, 'note' => ''),
            array('sample_label' => '', 'group_label' => '', 'target_gene' => '', 'ct_value' => null, 'reference_ct' => null, 'delta_ct' => null, 'delta_delta_ct' => null, 'fold_change' => null, 'replicate_count' => null, 'is_outlier' => 0, 'note' => ''),
            array('sample_label' => '', 'group_label' => '', 'target_gene' => '', 'ct_value' => null, 'reference_ct' => null, 'delta_ct' => null, 'delta_delta_ct' => null, 'fold_change' => null, 'replicate_count' => null, 'is_outlier' => 0, 'note' => ''),
            array('sample_label' => '', 'group_label' => '', 'target_gene' => '', 'ct_value' => null, 'reference_ct' => null, 'delta_ct' => null, 'delta_delta_ct' => null, 'fold_change' => null, 'replicate_count' => null, 'is_outlier' => 0, 'note' => ''),
        );
    }

    private function bindNullableFloat(\PDOStatement $req, string $param, ?float $value): void
    {
        if ($value === null) {
            $req->bindValue($param, null, PDO::PARAM_NULL);
            return;
        }
        $req->bindValue($param, $value);
    }

    private function readPrimerCatalog(): array
    {
        $req = $this->Db->prepare('SELECT
                id,
                code,
                target_gene,
                reference_gene,
                amplicon_bp,
                annealing_temperature,
                note
            FROM smartlab_primers
            WHERE team_id = :team
              AND is_active = 1
            ORDER BY code ASC');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    private function readPrimerObjectRef(int $id): array
    {
        if ($id <= 0) {
            return $this->getEmptyObjectRef();
        }
        $req = $this->Db->prepare('SELECT id, code, target_gene
            FROM smartlab_primers
            WHERE team_id = :team
              AND id = :id
            LIMIT 1');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row === false) {
            return $this->getEmptyObjectRef();
        }
        return array(
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['target_gene'],
        );
    }

    private function readAntibodyCatalog(): array
    {
        $req = $this->Db->prepare('SELECT
                id,
                code,
                name,
                application_scope,
                host_species,
                dilution_hint,
                target_name,
                note
            FROM smartlab_antibodies
            WHERE team_id = :team
              AND is_active = 1
            ORDER BY application_scope ASC, code ASC');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $rows = $req->fetchAll();
        foreach ($rows as &$row) {
            $row['antibody_role'] = $this->inferAntibodyRole($row);
        }
        unset($row);
        $materialRows = $this->readMaterialAntibodyCatalog();
        if ($materialRows === array()) {
            return $rows;
        }

        $seenCodes = array();
        foreach ($rows as $row) {
            $seenCodes[(string) ($row['code'] ?? '')] = true;
        }
        foreach ($materialRows as $row) {
            if (!isset($seenCodes[(string) ($row['code'] ?? '')])) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function readAntibodyObjectRef(int $id): array
    {
        if ($id <= 0) {
            return $id < 0 ? $this->readMaterialAntibodyObjectRef(abs($id)) : $this->getEmptyObjectRef();
        }
        $req = $this->Db->prepare('SELECT id, code, name
            FROM smartlab_antibodies
            WHERE team_id = :team
              AND id = :id
            LIMIT 1');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row === false) {
            return $this->getEmptyObjectRef();
        }
        return array(
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        );
    }

    private function readMaterialAntibodyCatalog(): array
    {
        $sql = 'SELECT
                m.id,
                m.code,
                m.name,
                m.category,
                m.brand,
                m.manufacturer,
                m.catalog_no,
                m.specification,
                m.package_size,
                COALESCE(SUM(l.quantity), 0) AS quantity,
                MIN(l.expires_at) AS expires_at
            FROM smartlab_materials AS m
            LEFT JOIN smartlab_material_lots AS l ON l.material_id = m.id
            WHERE m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("sample", "samples", "instrument", "instruments", "样本", "仪器"))
              AND (
                  m.category IN ("抗体", "antibody")
                  OR m.name LIKE "%抗%"
                  OR m.name LIKE "%Anti%"
                  OR m.code LIKE "%AB%"
              )
            GROUP BY
                m.id,
                m.code,
                m.name,
                m.category,
                m.brand,
                m.manufacturer,
                m.catalog_no,
                m.specification,
                m.package_size
            ORDER BY m.code ASC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        $rows = array();
        foreach ($req->fetchAll() as $row) {
            $name = (string) ($row['name'] ?? '');
            $rows[] = array(
                'id' => -1 * (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => $name,
                'application_scope' => $this->inferAntibodyApplicationScope($row),
                'antibody_role' => $this->inferAntibodyRole($row),
                'host_species' => (string) ($row['brand'] ?: $row['manufacturer'] ?: ''),
                'dilution_hint' => (string) ($row['specification'] ?: $row['package_size'] ?: ''),
                'target_name' => $this->inferAntibodyTargetName($name),
                'note' => trim(sprintf(
                    '库存对象%s%s',
                    ((float) ($row['quantity'] ?? 0)) > 0 ? ' · 余量 ' . rtrim(rtrim((string) $row['quantity'], '0'), '.') : '',
                    (string) ($row['expires_at'] ?? '') !== '' ? ' · 有效期 ' . (string) $row['expires_at'] : '',
                )),
            );
        }
        return $rows;
    }

    private function readMaterialAntibodyObjectRef(int $id): array
    {
        if ($id <= 0) {
            return $this->getEmptyObjectRef();
        }
        $req = $this->Db->prepare('SELECT id, code, name
            FROM smartlab_materials
            WHERE team_id = :team
              AND id = :id
            LIMIT 1');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row === false) {
            return $this->getEmptyObjectRef();
        }
        return array(
            'id' => -1 * (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        );
    }

    private function inferAntibodyApplicationScope(array $row): string
    {
        $code = strtolower((string) ($row['code'] ?? ''));
        $name = strtolower((string) ($row['name'] ?? ''));
        if (str_contains($code, 'r-if-') || str_contains($code, 'mat-ab-if-')) {
            return 'if';
        }
        if (str_contains($code, 'r-wb-') || str_contains($code, 'mat-ab-wb-')) {
            return 'western_blot';
        }
        if (str_contains($code, 'ihc')) {
            return 'ihc';
        }
        if (str_contains($code, 'elisa')) {
            return 'elisa';
        }
        $haystack = $code . ' ' . $name;
        if (str_contains($haystack, 'if') || str_contains($haystack, 'neun') || str_contains($haystack, 'alexa')) {
            return 'if';
        }
        return 'western_blot';
    }

    private function inferAntibodyTargetName(string $name): string
    {
        $target = preg_replace('/(一抗|二抗|抗体|Anti[- ]?)/iu', '', $name) ?? $name;
        return trim($target);
    }

    private function inferAntibodyRole(array $row): string
    {
        $haystack = strtolower((string) ($row['code'] ?? '') . ' ' . (string) ($row['name'] ?? '') . ' ' . (string) ($row['note'] ?? ''));
        if (str_contains($haystack, '二抗')
            || str_contains($haystack, 'secondary')
            || str_contains($haystack, 'hrp')
            || str_contains($haystack, 'alexa')
            || str_contains($haystack, 'goat anti')
            || str_contains($haystack, '羊抗')
        ) {
            return 'secondary';
        }
        return 'primary';
    }

    private function readReagentCatalog(): array
    {
        $req = $this->Db->prepare('SELECT
                id,
                code,
                name,
                reagent_scope,
                vendor,
                model_hint,
                note
            FROM smartlab_reagents
            WHERE team_id = :team
              AND is_active = 1
            ORDER BY reagent_scope ASC, code ASC');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $rows = $req->fetchAll();
        $materialRows = $this->readMaterialReagentCatalog();
        if ($materialRows === array()) {
            return $rows;
        }

        $seenCodes = array();
        foreach ($rows as $row) {
            $seenCodes[(string) ($row['code'] ?? '')] = true;
            $seenCodes['MAT-' . (string) ($row['code'] ?? '')] = true;
        }
        foreach ($materialRows as $row) {
            if (!isset($seenCodes[(string) ($row['code'] ?? '')])) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function readReagentObjectRef(int $id): array
    {
        if ($id <= 0) {
            return $id < 0 ? $this->readMaterialReagentObjectRef(abs($id)) : $this->getEmptyObjectRef();
        }
        $req = $this->Db->prepare('SELECT id, code, name
            FROM smartlab_reagents
            WHERE team_id = :team
              AND id = :id
            LIMIT 1');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row === false) {
            return $this->getEmptyObjectRef();
        }
        return array(
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        );
    }

    private function readMaterialReagentCatalog(): array
    {
        $sql = 'SELECT
                m.id,
                m.code,
                m.name,
                m.category,
                m.brand,
                m.manufacturer,
                m.catalog_no,
                m.specification,
                m.package_size,
                COALESCE(SUM(l.quantity), 0) AS quantity,
                MIN(l.expires_at) AS expires_at,
                SUBSTRING(GROUP_CONCAT(DISTINCT l.note SEPARATOR " "), 1, 512) AS lot_note
            FROM smartlab_materials AS m
            LEFT JOIN smartlab_material_lots AS l ON l.material_id = m.id
            WHERE m.team_id = :team
              AND (m.category IS NULL OR LOWER(m.category) NOT IN ("sample", "samples", "instrument", "instruments", "antibody", "primer", "样本", "仪器", "抗体", "引物"))
              AND (
                  LOWER(m.category) IN ("wb membrane", "wb detection", "key_material", "reagent")
                  OR LOWER(m.code) LIKE "%wb%"
                  OR LOWER(m.code) LIKE "%pvdf%"
                  OR LOWER(m.code) LIKE "%ecl%"
                  OR LOWER(m.code) LIKE "%mem%"
                  OR LOWER(m.name) LIKE "%western%"
                  OR LOWER(m.name) LIKE "%blot%"
                  OR LOWER(m.name) LIKE "%pvdf%"
                  OR LOWER(m.name) LIKE "%nitrocellulose%"
                  OR LOWER(m.name) LIKE "%membrane%"
                  OR LOWER(m.name) LIKE "%ecl%"
                  OR m.name LIKE "%膜%"
                  OR m.name LIKE "%显色%"
                  OR m.name LIKE "%显影%"
                  OR m.name LIKE "%发光%"
                  OR l.note LIKE "%Western Blot%"
                  OR l.note LIKE "%WB%"
                  OR l.note LIKE "%转膜%"
                  OR l.note LIKE "%检测液%"
                  OR l.note LIKE "%显影%"
              )
            GROUP BY
                m.id,
                m.code,
                m.name,
                m.category,
                m.brand,
                m.manufacturer,
                m.catalog_no,
                m.specification,
                m.package_size
            ORDER BY m.code ASC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        $rows = array();
        foreach ($req->fetchAll() as $row) {
            $scope = $this->inferMaterialReagentScope($row);
            if (!in_array($scope, array('western_blot_membrane', 'western_blot_detection'), true)) {
                continue;
            }
            $rows[] = array(
                'id' => -1 * (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'reagent_scope' => $scope,
                'vendor' => (string) ($row['brand'] ?: $row['manufacturer'] ?: ''),
                'model_hint' => (string) ($row['catalog_no'] ?: $row['specification'] ?: $row['package_size'] ?: ''),
                'note' => trim(sprintf(
                    '库存对象%s%s',
                    ((float) ($row['quantity'] ?? 0)) > 0 ? ' · 余量 ' . rtrim(rtrim((string) $row['quantity'], '0'), '.') : '',
                    (string) ($row['expires_at'] ?? '') !== '' ? ' · 有效期 ' . (string) $row['expires_at'] : '',
                )),
            );
        }
        return $rows;
    }

    private function readMaterialReagentObjectRef(int $id): array
    {
        if ($id <= 0) {
            return $this->getEmptyObjectRef();
        }
        $req = $this->Db->prepare('SELECT id, code, name
            FROM smartlab_materials
            WHERE team_id = :team
              AND id = :id
            LIMIT 1');
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':id', $id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch();
        if ($row === false) {
            return $this->getEmptyObjectRef();
        }
        return array(
            'id' => -1 * (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        );
    }

    private function inferMaterialReagentScope(array $row): string
    {
        $category = strtolower((string) ($row['category'] ?? ''));
        $haystack = strtolower(implode(' ', array(
            (string) ($row['code'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['category'] ?? ''),
            (string) ($row['catalog_no'] ?? ''),
            (string) ($row['specification'] ?? ''),
            (string) ($row['package_size'] ?? ''),
            (string) ($row['lot_note'] ?? ''),
        )));

        if (str_contains($category, 'wb membrane')
            || str_contains($haystack, 'pvdf')
            || str_contains($haystack, 'nitrocellulose')
            || str_contains($haystack, 'membrane')
            || str_contains($haystack, 'nc-mem')
            || str_contains($haystack, '转膜')
            || str_contains($haystack, '膜材')
        ) {
            return 'western_blot_membrane';
        }

        if (str_contains($category, 'wb detection')
            || str_contains($haystack, 'ecl')
            || str_contains($haystack, 'western blotting detection')
            || str_contains($haystack, 'chemiluminescence')
            || str_contains($haystack, '化学发光')
            || str_contains($haystack, '检测液')
            || str_contains($haystack, '显影')
            || (str_contains($haystack, 'wb') && str_contains($haystack, '显色'))
            || (str_contains($haystack, 'western') && str_contains($haystack, 'detection'))
        ) {
            return 'western_blot_detection';
        }

        return '';
    }

    private function normalizeAnalysisImagesJson(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }

        $items = isset($decoded['images']) && is_array($decoded['images']) ? $decoded['images'] : $decoded;
        if (!is_array($items)) {
            return array();
        }

        $normalized = array();
        foreach ($items as $item) {
            if (is_array($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function getEmptyObjectRef(): array
    {
        return array(
            'id' => 0,
            'code' => '',
            'name' => '',
        );
    }
}
