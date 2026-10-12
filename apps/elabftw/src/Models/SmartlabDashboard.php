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

use Elabftw\Models\Users\Users;
use PDO;

final class SmartlabDashboard extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
    }

    public function readOverview(): array
    {
        $isSupervisor = $this->isCurrentUserTeamSupervisor();
        $todayOwnerCondition = $isSupervisor ? '' : 'AND userid = :userid_today';
        $yesterdayOwnerCondition = $isSupervisor ? '' : 'AND userid = :userid_yesterday';
        $missingUploadOwnerCondition = $isSupervisor ? '' : 'AND e.userid = :userid_missing_upload';
        $reviewRequesterCondition = $isSupervisor ? '' : 'AND rf.requester_userid = :userid_review';

        $sql = 'SELECT
                (SELECT COUNT(*) FROM smartlab_projects WHERE team_id = :team) AS project_count,
                (SELECT COUNT(*) FROM smartlab_samples WHERE team_id = :team) AS sample_count,
                (
                    SELECT COUNT(*)
                    FROM experiments
                    WHERE team = :team
                      ' . $todayOwnerCondition . '
                      AND DATE(date) = CURRENT_DATE()
                ) AS today_experiment_count,
                (
                    SELECT COUNT(*)
                    FROM experiments
                    WHERE team = :team
                      ' . $yesterdayOwnerCondition . '
                      AND DATE(date) = CURRENT_DATE() - INTERVAL 1 DAY
                ) AS yesterday_experiment_count,
                (
                    SELECT COUNT(*)
                    FROM experiments AS e
                    WHERE e.team = :team
                      ' . $missingUploadOwnerCondition . '
                      AND NOT EXISTS (
                          SELECT 1
                          FROM uploads AS u
                          WHERE u.item_id = e.id
                            AND u.type = "experiments"
                      )
                ) AS experiment_without_upload_count,
                (
                    SELECT COUNT(*)
                    FROM experiments_templates
                    WHERE team = :team
                ) AS template_count,
                (
                    SELECT COUNT(*)
                    FROM smartlab_review_flows AS rf
                    LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
                    WHERE (p.team_id = :team OR (p.team_id IS NULL AND rf.requester_userid IN (
                        SELECT users_id FROM users2teams WHERE teams_id = :team
                    )))
                      ' . $reviewRequesterCondition . '
                      AND rf.status IN ("submitted", "needs_revision")
                ) AS pending_review_count,
                (
                    SELECT COUNT(*)
                    FROM containers2items AS c2i
                    INNER JOIN items AS i ON i.id = c2i.item_id
                    WHERE i.team = :team
                      AND i.state IN (1, 2)
                      AND c2i.qty_stored <= 2
                ) AS low_stock_count';
        $req = $this->Db->prepare($sql);
        if (!$isSupervisor) {
            $req->bindValue(':userid_today', $this->Users->userid, PDO::PARAM_INT);
            $req->bindValue(':userid_yesterday', $this->Users->userid, PDO::PARAM_INT);
            $req->bindValue(':userid_missing_upload', $this->Users->userid, PDO::PARAM_INT);
            $req->bindValue(':userid_review', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetch();
    }

    public function readNotebookContext(array $ids): array
    {
        new SmartlabBenchRecords($this->Users);
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if ($ids === array()) { return array(); }
        $sql = 'SELECT e.id, COUNT(DISTINCT u.id) AS upload_count,
            GROUP_CONCAT(DISTINCT CONCAT(p.code, " · ", p.name) SEPARATOR "、") AS project_label,
            COALESCE(rf.status, "") AS review_status,
            (SELECT JSON_UNQUOTE(JSON_EXTRACT(b.payload, "$.mode")) FROM smartlab_bench_records b WHERE b.experiment_id=e.id AND b.record_key=CONCAT("submission.",rf.id) ORDER BY b.revision DESC LIMIT 1) AS review_mode
            FROM experiments e
            LEFT JOIN uploads u ON u.item_id = e.id AND u.type = "experiments" AND u.state = 1
            LEFT JOIN smartlab_project_entities pe ON pe.entity_type = "experiment" AND pe.entity_id = e.id
            LEFT JOIN smartlab_projects p ON p.id = pe.project_id AND p.team_id = e.team
            LEFT JOIN smartlab_review_flows rf ON rf.id = (SELECT MAX(r.id) FROM smartlab_review_flows r WHERE r.entity_type = "experiment" AND r.entity_id = e.id)
            WHERE e.team = :team AND e.id IN (' . implode(',', $ids) . ')
            GROUP BY e.id, rf.status, rf.id';
        $query = $this->Db->prepare($sql);
        $query->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($query);
        $result = array();
        foreach ($query->fetchAll() as $row) { $result[(int) $row['id']] = $row; }
        return $result;
    }

    public function readRecentExperiments(int $limit = 6): array
    {
        $isSupervisor = $this->isCurrentUserTeamSupervisor();

        $sql = 'SELECT
                e.id,
                e.title,
                e.date,
                e.modified_at,
                e.metadata,
                COALESCE(p.code, "") AS project_code,
                COALESCE(p.name, "") AS project_name,
                COUNT(DISTINCT u.id) AS upload_count,
                COALESCE(latest_review.status, "") AS review_status
            FROM experiments AS e
            LEFT JOIN smartlab_project_entities AS pe
                ON pe.entity_type = "experiment"
               AND pe.entity_id = e.id
            LEFT JOIN smartlab_projects AS p
                ON p.id = pe.project_id
               AND p.team_id = e.team
            LEFT JOIN uploads AS u
                ON u.item_id = e.id
               AND u.type = "experiments"
            LEFT JOIN (
                SELECT rf1.entity_id, rf1.status
                FROM smartlab_review_flows AS rf1
                INNER JOIN (
                    SELECT entity_id, MAX(id) AS id
                    FROM smartlab_review_flows
                    WHERE entity_type = "experiment"
                    GROUP BY entity_id
                ) AS latest ON latest.id = rf1.id
            ) AS latest_review ON latest_review.entity_id = e.id
            WHERE e.team = :team
              ' . ($isSupervisor ? '' : 'AND e.userid = :userid') . '
            GROUP BY e.id, p.id, latest_review.status
            ORDER BY e.modified_at DESC, e.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        if (!$isSupervisor) {
            $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        $rows = $req->fetchAll();
        foreach ($rows as &$row) {
            $row['experiment_code'] = $this->extractExperimentCode((string) ($row['metadata'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    public function readOverviewAlerts(int $limit = 3): array
    {
        $alerts = array();

        $pendingReviews = $this->readPendingReviews($limit);
        foreach ($pendingReviews as $review) {
            $alerts[] = array(
                'level' => 'orange',
                'title' => '实验/样本待审核',
                'body' => sprintf(
                    '%s 记录处于 %s 状态。',
                    $review['project_code'] ?: ($review['entity_type'] ?: 'entity'),
                    $review['status'] ?: 'submitted',
                ),
                'href' => 'reviews.php',
            );
            if (count($alerts) >= $limit) {
                return $alerts;
            }
        }

        $recentSamples = $this->readRecentSampleEvents($limit);
        foreach ($recentSamples as $event) {
            $alerts[] = array(
                'level' => 'blue',
                'title' => $event['sample_code'] ?: '近期样本事件',
                'body' => trim(($event['event_type'] ?: 'sample event') . ' · ' . ($event['note'] ?: '无备注')),
                'href' => $event['experiment_id'] ? sprintf('experiments.php?mode=view&id=%d', (int) $event['experiment_id']) : 'database.php',
            );
            if (count($alerts) >= $limit) {
                return $alerts;
            }
        }

        if (!$alerts) {
            $alerts[] = array(
                'level' => 'green',
                'title' => '当前没有待处理提醒',
                'body' => '项目、审核与样本队列暂无新的风险事件。',
                'href' => 'smartlab-overview.php',
            );
        }

        return $alerts;
    }

    public function readCurrentUserContext(): array
    {
        $sql = 'SELECT
                u.userid,
                u.email,
                u.firstname,
                u.lastname,
                u.is_sysadmin,
                u.validated,
                u.last_login,
                u2t.is_owner,
                u2t.is_admin,
                t.name AS team_name
            FROM users AS u
            LEFT JOIN users2teams AS u2t ON u2t.users_id = u.userid AND u2t.teams_id = :team
            LEFT JOIN teams AS t ON t.id = u2t.teams_id
            WHERE u.userid = :userid
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch() ?: array();

        $role = '学生/实验人员';
        if ((int) ($row['is_sysadmin'] ?? 0) === 1) {
            $role = '系统管理员 / 团队管理员';
        } elseif ((int) ($row['is_owner'] ?? 0) === 1) {
            $role = '导师 / 团队管理员';
        } elseif ((int) ($row['is_admin'] ?? 0) === 1 || $this->hasAssistantProjectRole()) {
            $role = '助理 / 实验支持';
        }

        return array_merge($row, array('smartlab_role' => $role));
    }

    public function readProjectSnapshots(int $limit = 5): array
    {
        $sql = 'SELECT
                p.id,
                p.code,
                p.name,
                p.status,
                p.updated_at,
                COUNT(DISTINCT CASE WHEN pe.entity_type = "experiment" THEN pe.id END) AS experiment_count,
                COUNT(DISTINCT s.id) AS sample_count,
                COUNT(DISTINCT CASE WHEN rf.status IN ("submitted", "needs_revision") THEN rf.id END) AS pending_review_count
            FROM smartlab_projects AS p
            LEFT JOIN smartlab_project_entities AS pe ON pe.project_id = p.id
            LEFT JOIN smartlab_samples AS s ON s.project_id = p.id
            LEFT JOIN smartlab_review_flows AS rf ON rf.project_id = p.id
            WHERE p.team_id = :team
            GROUP BY p.id
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readPendingReviews(int $limit = 6): array
    {
        $isSupervisor = $this->isCurrentUserTeamSupervisor();
        $sql = 'SELECT
                rf.id,
                rf.entity_type,
                rf.entity_id,
                rf.status,
                rf.updated_at,
                p.code AS project_code,
                p.name AS project_name,
                e.title AS experiment_title,
                e.metadata AS experiment_metadata,
                CONCAT(req.firstname, " ", req.lastname) AS requester_name,
                CONCAT(assignee.firstname, " ", assignee.lastname) AS assignee_name,
                rt.decision,
                rt.comment
            FROM smartlab_review_flows AS rf
            LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
            LEFT JOIN experiments AS e
                ON rf.entity_type = "experiment"
               AND e.id = rf.entity_id
            LEFT JOIN users AS req ON req.userid = rf.requester_userid
            LEFT JOIN smartlab_review_tasks AS rt ON rt.flow_id = rf.id
            LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
            WHERE (p.team_id = :team OR (p.team_id IS NULL AND rf.requester_userid IN (
                SELECT users_id FROM users2teams WHERE teams_id = :team
            )))
              ' . ($isSupervisor ? '' : 'AND rf.requester_userid = :userid') . '
              AND rf.status IN ("submitted", "needs_revision")
            ORDER BY rf.updated_at DESC, rf.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        if (!$isSupervisor) {
            $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        $rows = $req->fetchAll();
        foreach ($rows as &$row) {
            $row['experiment_code'] = $this->extractExperimentCode((string) ($row['experiment_metadata'] ?? ''));
            $row['entity_label'] = match ((string) ($row['entity_type'] ?? '')) {
                'experiment' => $row['experiment_code'] ?: ($row['experiment_title'] ?: '实验记录'),
                'sample' => '样本记录',
                'item' => '库存记录',
                default => '待审核对象',
            };
        }
        unset($row);
        return $rows;
    }

    public function readRecentSampleEvents(int $limit = 6): array
    {
        $isSupervisor = $this->isCurrentUserTeamSupervisor();
        $sql = 'SELECT
                se.id,
                se.event_type,
                se.note,
                se.occurred_at,
                s.id AS sample_id,
                s.code AS sample_code,
                s.name AS sample_name,
                e.id AS experiment_id,
                e.title AS experiment_title,
                p.code AS project_code,
                p.name AS project_name,
                CONCAT(actor.firstname, " ", actor.lastname) AS actor_name
            FROM smartlab_sample_events AS se
            INNER JOIN smartlab_samples AS s ON s.id = se.sample_id
            LEFT JOIN experiments AS e ON e.id = se.experiment_id
            LEFT JOIN smartlab_projects AS p ON p.id = s.project_id
            LEFT JOIN users AS actor ON actor.userid = se.actor_userid
            WHERE s.team_id = :team
              ' . ($isSupervisor ? '' : 'AND (s.created_by = :userid OR se.actor_userid = :userid)') . '
            ORDER BY se.occurred_at DESC, se.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        if (!$isSupervisor) {
            $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readMyExperiments(int $limit = 6): array
    {
        $sql = 'SELECT
                e.id,
                e.title,
                e.date,
                e.modified_at,
                e.metadata,
                p.code AS project_code,
                p.name AS project_name
            FROM experiments AS e
            LEFT JOIN smartlab_project_entities AS pe
                ON pe.entity_type = "experiment"
               AND pe.entity_id = e.id
            LEFT JOIN smartlab_projects AS p
                ON p.id = pe.project_id
               AND p.team_id = e.team
            WHERE e.team = :team
              AND e.userid = :userid
            GROUP BY e.id, p.id
            ORDER BY e.modified_at DESC, e.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        $rows = $req->fetchAll();
        foreach ($rows as &$row) {
            $row['experiment_code'] = $this->extractExperimentCode((string) ($row['metadata'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    public function readMySamples(int $limit = 6): array
    {
        $sql = 'SELECT
                s.id,
                s.code,
                s.name,
                s.sample_type,
                s.status,
                s.updated_at,
                p.code AS project_code,
                p.name AS project_name
            FROM smartlab_samples AS s
            LEFT JOIN smartlab_projects AS p ON p.id = s.project_id
            WHERE s.team_id = :team
              AND s.created_by = :userid
            ORDER BY s.updated_at DESC, s.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readDemoResultBundles(): array
    {
        $manualBundles = $this->readManualResultBundles();
        if ($manualBundles !== array()) {
            return $manualBundles;
        }

        $isSupervisor = $this->isCurrentUserTeamSupervisor();

        $sql = 'SELECT
                e.id AS experiment_id,
                e.title,
                e.date,
                e.modified_at,
                e.metadata,
                e.userid,
                CONCAT(owner.firstname, " ", owner.lastname) AS owner_name,
                u.id AS upload_id,
                u.real_name,
                u.long_name,
                u.storage,
                u.filesize
            FROM experiments AS e
            LEFT JOIN users AS owner ON owner.userid = e.userid
            LEFT JOIN uploads AS u
                ON u.item_id = e.id
               AND u.type = "experiments"
               AND u.state = 1
            WHERE e.team = :team
              AND (
                e.title LIKE "SmartLab Demo Result%"
                OR JSON_UNQUOTE(JSON_EXTRACT(e.metadata, "$.smartlab_demo")) = "patent21_five_accounts"
              )
              ' . ($isSupervisor ? '' : 'AND e.userid = :userid') . '
            ORDER BY e.id DESC, u.id ASC';
        $req = $this->Db->prepare($sql);
        if (!$isSupervisor) {
            $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        $bundles = array();
        foreach ($req->fetchAll() as $row) {
            $assay = $this->classifyDemoAssay((string) $row['title'], (string) ($row['metadata'] ?? ''));
            $experimentCode = $this->extractExperimentCode((string) ($row['metadata'] ?? ''));
            $projectCode = $this->extractProjectCode((string) ($row['metadata'] ?? ''));
            if (!isset($bundles[$assay])) {
                $bundles[$assay] = array(
                    'assay' => $assay,
                    'label' => $this->demoAssayLabel($assay),
                    'experiment_id' => (int) $row['experiment_id'],
                    'experiment_code' => $experimentCode,
                    'project_code' => $projectCode,
                    'title' => $row['title'],
                    'date' => $row['date'],
                    'modified_at' => $row['modified_at'],
                    'metadata' => (string) ($row['metadata'] ?? ''),
                    'owner_userid' => (int) $row['userid'],
                    'owner_name' => trim((string) ($row['owner_name'] ?? '')),
                    'summary' => $this->demoAssaySummary($assay),
                    'analysis_points' => $this->demoAssayAnalysisPoints($assay),
                    'uploads' => array(),
                    'image_uploads' => array(),
                    'data_uploads' => array(),
                    'upload_count' => 0,
                    'total_filesize' => 0,
                );
            }

            if ((int) $row['experiment_id'] !== (int) $bundles[$assay]['experiment_id']) {
                continue;
            }

            if ($row['upload_id'] === null) {
                continue;
            }

            $upload = array(
                'id' => (int) $row['upload_id'],
                'real_name' => $row['real_name'],
                'long_name' => $row['long_name'],
                'storage' => (int) $row['storage'],
                'filesize' => (int) $row['filesize'],
                'href' => sprintf(
                    'app/download.php?f=%s&storage=%d&name=%s',
                    rawurlencode((string) $row['long_name']),
                    (int) $row['storage'],
                    rawurlencode((string) $row['real_name']),
                ),
                'kind' => $this->uploadKind((string) $row['real_name']),
            );
            $bundles[$assay]['uploads'][] = $upload;
            $bundles[$assay]['upload_count']++;
            $bundles[$assay]['total_filesize'] += (int) $row['filesize'];
            if ($upload['kind'] === 'image') {
                $bundles[$assay]['image_uploads'][] = $upload;
            } else {
                $bundles[$assay]['data_uploads'][] = $upload;
            }
        }

        return $bundles;
    }

    private function readManualResultBundles(): array
    {
        $isSupervisor = $this->isCurrentUserTeamSupervisor();
        $sql = 'SELECT
                e.id AS experiment_id,
                e.title,
                e.date,
                e.modified_at,
                e.metadata,
                e.userid,
                CONCAT(owner.firstname, " ", owner.lastname) AS owner_name,
                u.id AS upload_id,
                u.real_name,
                u.long_name,
                u.storage,
                u.filesize
            FROM experiments AS e
            LEFT JOIN users AS owner ON owner.userid = e.userid
            LEFT JOIN uploads AS u
                ON u.item_id = e.id
               AND u.type = "experiments"
               AND u.state = 1
            WHERE e.team = :team
              AND e.title LIKE "学生手动录入实验数据%"
              ' . ($isSupervisor ? '' : 'AND e.userid = :userid') . '
            ORDER BY e.id DESC, u.id ASC';
        $req = $this->Db->prepare($sql);
        if (!$isSupervisor) {
            $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        $rows = $req->fetchAll();
        if ($rows === array()) {
            return array();
        }

        $bundles = array();

        foreach ($rows as $row) {
            $assay = $this->classifyManualUploadAssay((string) $row['real_name']);
            if ($assay === 'other') {
                $assay = $this->classifyDemoAssay((string) $row['title'], (string) ($row['metadata'] ?? ''));
                if ($assay === 'other') {
                    $assay = 'selected';
                }
            }
            $bundleKey = isset($bundles[$assay]) && (int) ($bundles[$assay]['experiment_id'] ?? 0) !== (int) $row['experiment_id']
                ? $assay . ':' . (int) $row['experiment_id']
                : $assay;
            if (!isset($bundles[$bundleKey])) {
                $bundles[$bundleKey] = array(
                    'assay' => $assay,
                    'label' => $this->demoAssayLabel($assay),
                    'experiment_id' => (int) $row['experiment_id'],
                    'experiment_code' => $this->extractExperimentCode((string) ($row['metadata'] ?? '')),
                    'project_code' => $this->extractProjectCode((string) ($row['metadata'] ?? '')),
                    'title' => $row['title'],
                    'date' => $row['date'],
                    'modified_at' => $row['modified_at'],
                    'metadata' => (string) ($row['metadata'] ?? ''),
                    'owner_userid' => (int) ($row['userid'] ?? 0),
                    'owner_name' => trim((string) ($row['owner_name'] ?? '')),
                    'summary' => $this->demoAssaySummary($assay),
                    'analysis_points' => $this->demoAssayAnalysisPoints($assay),
                    'uploads' => array(),
                    'image_uploads' => array(),
                    'data_uploads' => array(),
                    'upload_count' => 0,
                    'total_filesize' => 0,
                    'source_upload_count' => 0,
                    'source_total_filesize' => 0,
                );
            }
            if ($row['upload_id'] === null) {
                continue;
            }
            $upload = $this->buildUploadRow($row);
            $bundles[$bundleKey]['source_upload_count']++;
            $bundles[$bundleKey]['source_total_filesize'] += (int) $row['filesize'];
            $bundles[$bundleKey]['uploads'][] = $upload;
            $bundles[$bundleKey]['upload_count']++;
            $bundles[$bundleKey]['total_filesize'] += (int) $row['filesize'];
            if ($upload['kind'] === 'image') {
                $bundles[$bundleKey]['image_uploads'][] = $upload;
            } else {
                $bundles[$bundleKey]['data_uploads'][] = $upload;
            }
        }

        return $bundles;
    }

    private function buildUploadRow(array $row): array
    {
        return array(
            'id' => (int) $row['upload_id'],
            'real_name' => $row['real_name'],
            'long_name' => $row['long_name'],
            'storage' => (int) $row['storage'],
            'filesize' => (int) $row['filesize'],
            'href' => sprintf(
                'app/download.php?f=%s&storage=%d&name=%s',
                rawurlencode((string) $row['long_name']),
                (int) $row['storage'],
                rawurlencode((string) $row['real_name']),
            ),
            'kind' => $this->uploadKind((string) $row['real_name']),
        );
    }

    public function readPrimaryDemoResult(): array
    {
        $bundles = $this->readDemoResultBundles();
        foreach (array('if', 'qpcr', 'elisa', 'wb') as $assay) {
            if (isset($bundles[$assay])) {
                return $bundles[$assay];
            }
        }

        return array();
    }

    private function classifyDemoAssay(string $title, string $metadata): string
    {
        $decoded = json_decode($metadata, true);
        $metadataAssay = '';
        if (is_array($decoded)) {
            $metadataAssay = strtolower((string) ($decoded['assay'] ?? $decoded['pack_key'] ?? ''));
        }
        $normalizedTitle = strtolower($title);

        if (in_array($metadataAssay, array('qpcr', 'qpcr 记录'), true) || str_contains($title, 'qPCR') || str_contains($normalizedTitle, 'qpcr')) {
            return 'qpcr';
        }
        if ($metadataAssay === 'elisa' || str_contains($title, 'ELISA') || str_contains($normalizedTitle, 'elisa')) {
            return 'elisa';
        }
        if (in_array($metadataAssay, array('wb', 'western_blot', 'western blot 记录'), true)
            || str_contains($title, 'Western')
            || str_contains($normalizedTitle, 'western')
            || str_contains($normalizedTitle, 'blot')
            || str_contains($title, 'WB')
        ) {
            return 'wb';
        }
        if (in_array($metadataAssay, array('if', '免疫荧光记录'), true)
            || str_contains($title, 'IF')
            || str_contains($title, '免疫荧光')
            || str_contains($normalizedTitle, 'immunofluorescence')
        ) {
            return 'if';
        }

        return 'other';
    }

    private function classifyManualUploadAssay(string $filename): string
    {
        if (str_contains($filename, 'qPCR')
            || str_contains($filename, 'QPCR')
            || str_contains($filename, '扩增曲线')
            || str_contains($filename, '熔解曲线')
            || str_contains($filename, 'amplification')
            || str_contains($filename, 'fold_change')
            || str_contains($filename, '20_36_32 (1)')
            || str_contains($filename, '20_36_33 (5)')
            || str_contains($filename, '20_37_12 (1)')
            || str_contains($filename, '20_37_12 (2)')
        ) {
            return 'qpcr';
        }
        if (str_contains($filename, 'ELISA')
            || str_contains($filename, 'standard_curve')
            || str_contains($filename, 'sample_concentration')
            || str_contains($filename, '20_36_33 (4)')
            || str_contains($filename, '20_36_33 (8)')
            || str_contains($filename, '20_37_13 (5)')
        ) {
            return 'elisa';
        }
        if (str_contains($filename, 'Western')
            || str_contains($filename, 'blot')
            || str_contains($filename, 'WB_')
            || str_contains($filename, 'WB原始膜图')
            || str_contains($filename, '20_36_33 (2)')
            || str_contains($filename, '20_36_33 (6)')
            || str_contains($filename, '20_37_12 (3)')
        ) {
            return 'wb';
        }
        if (str_contains($filename, 'Immunofluorescence')
            || str_contains($filename, 'IF_')
            || str_contains($filename, 'IF第')
            || str_contains($filename, '荧光')
            || str_contains($filename, '20_36_33 (3)')
            || str_contains($filename, '20_36_33 (7)')
            || str_contains($filename, '20_37_13 (4)')
        ) {
            return 'if';
        }

        return 'other';
    }

    private function extractExperimentCode(string $metadata): string
    {
        if ($metadata === '') {
            return '';
        }
        $decoded = json_decode($metadata, true);
        if (!is_array($decoded)) {
            return '';
        }
        $code = (string) ($decoded['experiment_code'] ?? $decoded['exp_code'] ?? $decoded['code'] ?? '');
        return trim($code);
    }

    private function extractProjectCode(string $metadata): string
    {
        if ($metadata === '') {
            return '';
        }
        $decoded = json_decode($metadata, true);
        if (!is_array($decoded)) {
            return '';
        }
        $code = (string) ($decoded['project_code'] ?? '');
        return trim($code);
    }

    private function uploadKind(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array('png', 'jpg', 'jpeg', 'svg', 'tif', 'tiff'), true) ? 'image' : 'data';
    }

    private function demoAssayLabel(string $assay): string
    {
        return match ($assay) {
            'qpcr' => 'qPCR 结果',
            'elisa' => 'ELISA 结果',
            'wb' => 'Western blot 结果',
            'if' => '免疫荧光结果',
            'selected' => '当前实验结果',
            default => '实验结果',
        };
    }

    private function demoAssaySummary(string $assay): string
    {
        return match ($assay) {
            'qpcr' => '读取 Ct 原始值、计算 delta-delta Ct，并生成扩增曲线与 fold-change 图。',
            'elisa' => '读取 OD450 标准品与未知样本，生成标准曲线并反算浓度。',
            'wb' => '读取 VGlut1、GAD67 与 GAPDH 条带灰度，完成归一化强度和 E/I 比值计算。',
            'if' => '读取 DAPI/FITC 合并图和细胞计数表，输出阳性细胞比例。',
            default => '读取实验附件并生成结构化分析摘要。',
        };
    }

    private function demoAssayAnalysisPoints(string $assay): array
    {
        return match ($assay) {
            'qpcr' => array(
                array('name' => '目标基因', 'value' => 'mTOR / Rheb / Lamtor1'),
                array('name' => '内参基因', 'value' => 'GAPDH'),
                array('name' => 'mTOR fold-change', 'value' => '2.83'),
                array('name' => 'Rheb fold-change', 'value' => '2.45'),
                array('name' => 'Lamtor1 fold-change', 'value' => '1.12'),
                array('name' => '判定', 'value' => 'mTOR、Rheb 超出阈值，需断点复核'),
            ),
            'elisa' => array(
                array('name' => '检测波长', 'value' => 'OD450'),
                array('name' => '最高未知样本', 'value' => 'Sample-C 602.1 pg/mL'),
                array('name' => '标准曲线', 'value' => '0-1000 pg/mL'),
                array('name' => '判定', 'value' => '未知样本浓度已反算'),
            ),
            'wb' => array(
                array('name' => '目标蛋白', 'value' => 'VGlut1 / GAD67'),
                array('name' => '内参', 'value' => 'GAPDH'),
                array('name' => 'AD+PCN GAD67恢复', 'value' => '0.84'),
                array('name' => '判定', 'value' => 'PCN处理后E/I失衡趋势改善'),
            ),
            'if' => array(
                array('name' => '通道识别', 'value' => 'DAPI / 488 / 594 / Merge'),
                array('name' => 'NeuN阳性率', 'value' => 'Aβ组降低，Aβ+PME组恢复'),
                array('name' => 'GFAP信号', 'value' => 'Aβ组增强，PME后下降'),
                array('name' => '判定', 'value' => 'PME对Aβ诱导损伤有保护趋势'),
            ),
            default => array(),
        };
    }

    public function readMyReviews(int $limit = 6): array
    {
        $isSupervisor = $this->isCurrentUserTeamSupervisor();
        $sql = 'SELECT
                e.id AS experiment_id,
                e.title AS experiment_title,
                e.date AS experiment_date,
                e.modified_at,
                e.metadata,
                e.userid AS student_userid,
                CONCAT(student.firstname, " ", student.lastname) AS student_name,
                student.email AS student_email,
                COALESCE(ap.pack_key, "") AS pack_key,
                COUNT(DISTINCT u.id) AS upload_count,
                rf.id AS review_flow_id,
                COALESCE(rf.status, "not_submitted") AS status,
                rf.updated_at AS review_updated_at,
                COALESCE(GROUP_CONCAT(DISTINCT p.code ORDER BY p.code SEPARATOR ", "), "") AS project_code,
                COALESCE(GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ", "), "") AS project_name,
                CONCAT(assignee.firstname, " ", assignee.lastname) AS assignee_name,
                rt.decision,
                rt.comment,
                rt.decided_at
            FROM experiments AS e
            LEFT JOIN smartlab_project_entities AS pe
                ON pe.entity_type = "experiment"
               AND pe.entity_id = e.id
            LEFT JOIN smartlab_projects AS p
                ON p.id = pe.project_id
               AND p.team_id = e.team
            LEFT JOIN smartlab_assay_packs AS ap
                ON ap.experiment_id = e.id
               AND ap.team_id = e.team
            LEFT JOIN uploads AS u
                ON u.type = "experiments"
               AND u.item_id = e.id
            LEFT JOIN (
                SELECT rf1.*
                FROM smartlab_review_flows AS rf1
                INNER JOIN (
                    SELECT entity_id, requester_userid, MAX(id) AS id
                    FROM smartlab_review_flows
                    WHERE entity_type = "experiment"
                    GROUP BY entity_id, requester_userid
                ) AS latest ON latest.id = rf1.id
            ) AS rf
                ON rf.entity_type = "experiment"
               AND rf.entity_id = e.id
               AND rf.requester_userid = e.userid
            LEFT JOIN (
                SELECT rt1.*
                FROM smartlab_review_tasks AS rt1
                INNER JOIN (
                    SELECT flow_id, MAX(id) AS id
                    FROM smartlab_review_tasks
                    GROUP BY flow_id
                ) AS latest_task ON latest_task.id = rt1.id
            ) AS rt ON rt.flow_id = rf.id
            LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
            LEFT JOIN users AS student ON student.userid = e.userid
            WHERE e.team = :team
              ' . ($isSupervisor ? '' : 'AND e.userid = :userid') . '
            GROUP BY
                e.id,
                e.title,
                e.date,
                e.modified_at,
                e.userid,
                student.firstname,
                student.lastname,
                student.email,
                ap.pack_key,
                rf.id,
                rf.status,
                rf.updated_at,
                assignee.firstname,
                assignee.lastname,
                rt.decision,
                rt.comment,
                rt.decided_at
            ORDER BY
                CASE COALESCE(rf.status, "not_submitted")
                    WHEN "needs_revision" THEN 1
                    WHEN "submitted" THEN 2
                    WHEN "not_submitted" THEN 3
                    WHEN "rejected" THEN 4
                    WHEN "approved" THEN 5
                    ELSE 6
                END,
                COALESCE(rf.updated_at, e.modified_at) DESC,
                e.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        if (!$isSupervisor) {
            $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        }
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        $rows = $req->fetchAll();
        foreach ($rows as &$row) {
            $row['experiment_code'] = $this->extractExperimentCode((string) ($row['metadata'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    private function isCurrentUserTeamSupervisor(): bool
    {
        if ((int) ($this->Users->userData['is_sysadmin'] ?? 0) === 1
            || (int) ($this->Users->userData['can_manage_users2teams'] ?? 0) === 1
        ) {
            return true;
        }

        $req = $this->Db->prepare('SELECT is_owner, is_admin FROM users2teams WHERE users_id = :userid AND teams_id = :team LIMIT 1');
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $row = $req->fetch() ?: array();

        return (int) ($row['is_owner'] ?? 0) === 1
            || (int) ($row['is_admin'] ?? 0) === 1
            || $this->hasAssistantProjectRole();
    }

    private function hasAssistantProjectRole(): bool
    {
        $userid = (int) ($this->Users->userData['userid'] ?? $this->Users->userid ?? 0);
        $team = (int) ($this->Users->team ?? $this->Users->userData['team'] ?? 0);
        $teamCondition = $team > 0 ? 'AND p.team_id = :team' : '';
        $req = $this->Db->prepare('SELECT 1
            FROM smartlab_project_members AS pm
            INNER JOIN smartlab_projects AS p ON p.id = pm.project_id
            WHERE pm.user_id = :userid
              ' . $teamCondition . '
              AND pm.role = "助理"
            LIMIT 1');
        $req->bindValue(':userid', $userid, PDO::PARAM_INT);
        if ($team > 0) {
            $req->bindValue(':team', $team, PDO::PARAM_INT);
        }
        $this->Db->execute($req);
        return $req->fetch() !== false;
    }
}
