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
use PDO;
use JsonException;

use function in_array;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final class SmartlabSamples extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
    }

    public function readAll(string $status = '', ?int $projectId = null, string $scope = 'all'): array
    {
        $whereSql = '';
        if ($status !== '') {
            $whereSql .= ' AND s.status = :status';
        }
        if ($projectId !== null) {
            if ($projectId > 0) {
                $whereSql .= ' AND s.project_id = :project_id';
            } elseif ($projectId === 0) {
                $whereSql .= ' AND s.project_id IS NULL';
            }
        }
        if ($scope === 'mine') {
            $whereSql .= ' AND s.created_by = :userid';
        } elseif ($scope === 'my_projects') {
            $whereSql .= ' AND EXISTS (
                SELECT 1
                FROM smartlab_project_members AS pm
                WHERE pm.project_id = s.project_id
                  AND pm.user_id = :userid
            )';
        }

        $sql = 'SELECT
                s.id,
                s.code,
                s.name,
                s.sample_type,
                s.status,
                s.quantity,
                s.unit,
                p.code AS project_code,
                p.name AS project_name,
                i.title AS source_item_title
            FROM smartlab_samples AS s
            LEFT JOIN smartlab_projects AS p ON p.id = s.project_id
            LEFT JOIN items AS i ON i.id = s.source_item_id
            WHERE s.team_id = :team' . $whereSql . '
            ORDER BY s.updated_at DESC, s.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if ($status !== '') {
            $req->bindValue(':status', $status);
        }
        if ($projectId !== null && $projectId > 0) {
            $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        }
        if ($scope === 'mine' || $scope === 'my_projects') {
            $req->bindValue(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        }
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    /**
     * @throws JsonException
     */
    public function create(
        string $code,
        string $name,
        string $sampleType,
        ?int $projectId,
        ?float $quantity,
        string $unit,
        array $metadata,
    ): int {
        $sql = 'INSERT INTO smartlab_samples (
                team_id,
                project_id,
                code,
                name,
                sample_type,
                status,
                quantity,
                unit,
                metadata,
                created_by
            ) VALUES (
                :team,
                :project_id,
                :code,
                :name,
                :sample_type,
                "active",
                :quantity,
                :unit,
                :metadata,
                :created_by
            )';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, $projectId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $req->bindValue(':code', $code);
        $req->bindValue(':name', $name);
        $req->bindValue(':sample_type', $sampleType);
        $req->bindValue(':quantity', $quantity, $quantity === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $req->bindValue(':unit', $unit !== '' ? $unit : null, $unit === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $req->bindValue(':metadata', json_encode($metadata, JSON_THROW_ON_ERROR));
        $req->bindValue(':created_by', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);

        $sampleId = (int) $this->Db->lastInsertId();
        $this->addEvent($sampleId, 'registered', null, $quantity, '学生端登记样本');

        if ($projectId !== null) {
            $this->linkSampleToProject($projectId, $sampleId);
        }

        return $sampleId;
    }

    public function addEvent(int $sampleId, string $eventType, ?int $experimentId, ?float $quantityDelta, string $note): void
    {
        $this->assertSampleInTeam($sampleId);

        $sql = 'INSERT INTO smartlab_sample_events (
                sample_id,
                event_type,
                experiment_id,
                actor_userid,
                quantity_delta,
                note
            ) VALUES (
                :sample_id,
                :event_type,
                :experiment_id,
                :actor_userid,
                :quantity_delta,
                :note
            )';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $req->bindValue(':event_type', $eventType);
        $req->bindValue(':experiment_id', $experimentId, $experimentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $req->bindValue(':actor_userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $req->bindValue(':quantity_delta', $quantityDelta, $quantityDelta === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $req->bindValue(':note', $note !== '' ? $note : null, $note === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $this->Db->execute($req);

        $this->applyEventToSampleState($sampleId, $eventType, $quantityDelta);
    }

    /**
     * @throws JsonException
     */
    public function update(
        int $sampleId,
        string $code,
        string $name,
        string $sampleType,
        string $status,
        ?int $projectId,
        ?float $quantity,
        string $unit,
        array $metadata,
        string $changeNote = '',
    ): void {
        $this->assertSampleInTeam($sampleId);
        $status = $this->normalizeStatus($status);

        $sql = 'UPDATE smartlab_samples
            SET project_id = :project_id,
                code = :code,
                name = :name,
                sample_type = :sample_type,
                status = :status,
                quantity = :quantity,
                unit = :unit,
                metadata = :metadata
            WHERE id = :sample_id
              AND team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, $projectId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $req->bindValue(':code', $code);
        $req->bindValue(':name', $name);
        $req->bindValue(':sample_type', $sampleType);
        $req->bindValue(':status', $status);
        $req->bindValue(':quantity', $quantity, $quantity === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $req->bindValue(':unit', $unit !== '' ? $unit : null, $unit === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $req->bindValue(':metadata', json_encode($metadata, JSON_THROW_ON_ERROR));
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        $this->syncSampleProjectLink($projectId, $sampleId);
        $this->addEvent(
            $sampleId,
            'updated',
            null,
            null,
            $changeNote !== '' ? $changeNote : sprintf('样本基础信息已更新，当前状态：%s。', $this->statusLabel($status)),
        );
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function readOne(int $sampleId): array
    {
        $sql = 'SELECT
                s.id,
                s.code,
                s.name,
                s.sample_type,
                s.status,
                s.quantity,
                s.unit,
                s.metadata,
                s.created_at,
                s.updated_at,
                p.id AS project_id,
                p.code AS project_code,
                p.name AS project_name,
                i.id AS source_item_id,
                i.title AS source_item_title,
                CONCAT(creator.firstname, " ", creator.lastname) AS creator_name
            FROM smartlab_samples AS s
            LEFT JOIN smartlab_projects AS p ON p.id = s.project_id
            LEFT JOIN items AS i ON i.id = s.source_item_id
            LEFT JOIN users AS creator ON creator.userid = s.created_by
            WHERE s.team_id = :team
              AND s.id = :sample_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $this->Db->execute($req);

        $sample = $this->Db->fetch($req);
        $sample['metadata_arr'] = $this->decodeMetadata((string) ($sample['metadata'] ?? ''));
        $sample['status_label'] = $this->statusLabel((string) ($sample['status'] ?? ''));
        return $sample;
    }

    public function readEvents(int $sampleId): array
    {
        $sql = 'SELECT
                se.id,
                se.event_type,
                se.experiment_id,
                se.quantity_delta,
                se.note,
                se.occurred_at,
                e.title AS experiment_title,
                CONCAT(actor.firstname, " ", actor.lastname) AS actor_name
            FROM smartlab_sample_events AS se
            INNER JOIN smartlab_samples AS s ON s.id = se.sample_id
            LEFT JOIN experiments AS e ON e.id = se.experiment_id
            LEFT JOIN users AS actor ON actor.userid = se.actor_userid
            WHERE s.team_id = :team
              AND se.sample_id = :sample_id
            ORDER BY se.occurred_at DESC, se.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readLinkedExperiments(int $sampleId): array
    {
        $sql = 'SELECT
                e.id,
                e.title,
                e.date,
                e.modified_at,
                p.id AS project_id,
                p.code AS project_code,
                p.name AS project_name
            FROM smartlab_sample_events AS se
            INNER JOIN smartlab_samples AS s ON s.id = se.sample_id
            INNER JOIN experiments AS e ON e.id = se.experiment_id
            LEFT JOIN smartlab_project_entities AS pe
                ON pe.entity_type = "experiment"
               AND pe.entity_id = e.id
            LEFT JOIN smartlab_projects AS p
                ON p.id = pe.project_id
               AND p.team_id = s.team_id
            WHERE s.team_id = :team
              AND se.sample_id = :sample_id
              AND se.experiment_id IS NOT NULL
            GROUP BY e.id, p.id
            ORDER BY MAX(se.occurred_at) DESC, e.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readReviewFlows(int $sampleId): array
    {
        $sql = 'SELECT
                rf.id,
                rf.status,
                rf.created_at,
                rf.updated_at,
                p.id AS project_id,
                p.code AS project_code,
                p.name AS project_name,
                CONCAT(req.firstname, " ", req.lastname) AS requester_name,
                CONCAT(assignee.firstname, " ", assignee.lastname) AS assignee_name,
                rt.decision,
                rt.comment,
                rt.decided_at
            FROM smartlab_review_flows AS rf
            INNER JOIN smartlab_samples AS s ON s.id = rf.entity_id
            LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
            LEFT JOIN users AS req ON req.userid = rf.requester_userid
            LEFT JOIN smartlab_review_tasks AS rt ON rt.flow_id = rf.id
            LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
            WHERE rf.entity_type = "sample"
              AND rf.entity_id = :sample_id
              AND s.team_id = :team
            ORDER BY rf.updated_at DESC, rf.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    private function linkSampleToProject(int $projectId, int $sampleId): void
    {
        $sql = 'INSERT IGNORE INTO smartlab_project_entities (project_id, entity_type, entity_id)
            SELECT p.id, "sample", :sample_id
            FROM smartlab_projects AS p
            INNER JOIN smartlab_project_members AS pm ON pm.project_id = p.id
            WHERE p.team_id = :team
              AND p.id = :project_id
              AND pm.user_id = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    private function syncSampleProjectLink(?int $projectId, int $sampleId): void
    {
        $sql = 'DELETE pe
            FROM smartlab_project_entities AS pe
            INNER JOIN smartlab_projects AS p ON p.id = pe.project_id
            WHERE p.team_id = :team
              AND pe.entity_type = "sample"
              AND pe.entity_id = :sample_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $this->Db->execute($req);

        if ($projectId !== null) {
            $this->linkSampleToProject($projectId, $sampleId);
        }
    }

    private function applyEventToSampleState(int $sampleId, string $eventType, ?float $quantityDelta): void
    {
        $sample = $this->readOne($sampleId);
        $currentQuantity = $sample['quantity'] !== null ? (float) $sample['quantity'] : null;
        $nextQuantity = $currentQuantity;
        $eventType = trim($eventType);
        if ($quantityDelta !== null && $currentQuantity !== null && !in_array($eventType, array('registered', 'updated'), true)) {
            $nextQuantity = max(0.0, $currentQuantity + $quantityDelta);
        }

        $nextStatus = (string) $sample['status'];
        if ($eventType === 'archived') {
            $nextStatus = 'archived';
        } elseif ($eventType === 'discarded') {
            $nextStatus = 'discarded';
        } elseif ($eventType === 'consumed' && ($quantityDelta === null || $nextQuantity === null || $nextQuantity <= 0.0)) {
            $nextStatus = 'consumed';
        } elseif (in_array($eventType, array('aliquoted', 'moved'), true) && $nextStatus === 'consumed' && ($nextQuantity === null || $nextQuantity > 0.0)) {
            $nextStatus = 'active';
        }

        $sql = 'UPDATE smartlab_samples
            SET quantity = :quantity,
                status = :status
            WHERE id = :sample_id
              AND team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':quantity', $nextQuantity, $nextQuantity === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $req->bindValue(':status', $nextStatus);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, array('active', 'reserved', 'consumed', 'archived', 'discarded'), true)
            ? $status
            : 'active';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => '在用',
            'reserved' => '留样',
            'consumed' => '已用完',
            'archived' => '已归档',
            'discarded' => '已废弃',
            default => $status !== '' ? $status : '未知',
        };
    }

    private function decodeMetadata(string $metadata): array
    {
        if ($metadata === '') {
            return array();
        }

        try {
            $decoded = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : array();
        } catch (JsonException) {
            return array();
        }
    }

    /**
     * Upsert a sample by team+code. Returns the sample ID.
     * If the sample doesn't exist, creates it. If it exists, updates it.
     */
    public function upsertSample(
        string $code,
        string $name,
        string $sampleType,
        ?int $projectId,
        array $metadata,
    ): int {
        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $sql = 'INSERT INTO smartlab_samples
            (team_id, project_id, code, name, sample_type, status, metadata, created_by)
            VALUES (:team, :project_id, :code, :name, :sample_type, "active", :metadata, :userid)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                sample_type = VALUES(sample_type),
                project_id = VALUES(project_id),
                metadata = VALUES(metadata),
                updated_at = CURRENT_TIMESTAMP';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        if ($projectId === null) {
            $req->bindValue(':project_id', null, PDO::PARAM_NULL);
        } else {
            $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        }
        $req->bindValue(':code', $code);
        $req->bindValue(':name', $name);
        $req->bindValue(':sample_type', $sampleType);
        $req->bindValue(':metadata', $metadataJson ?: '{}');
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);

        $sampleId = $this->Db->lastInsertId();
        if ($sampleId === 0) {
            $lookup = $this->Db->prepare('SELECT id FROM smartlab_samples WHERE team_id = :team AND code = :code LIMIT 1');
            $lookup->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
            $lookup->bindValue(':code', $code);
            $this->Db->execute($lookup);
            $found = $lookup->fetchColumn();
            if ($found !== false) {
                return (int) $found;
            }
        }

        return $sampleId;
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function assertSampleInTeam(int $sampleId): void
    {
        $sql = 'SELECT id
            FROM smartlab_samples
            WHERE team_id = :team
              AND id = :sample_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':sample_id', $sampleId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $this->Db->fetch($req);
    }
}
