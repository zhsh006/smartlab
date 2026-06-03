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

use Elabftw\Elabftw\Db;
use Elabftw\Models\Users\Users;
use PDO;

final class SmartlabDashboard
{
    private Db $Db;

    public function __construct(private readonly Users $Users)
    {
        $this->Db = Db::getConnection();
    }

    public function readOverview(): array
    {
        $sql = 'SELECT
                (SELECT COUNT(*) FROM smartlab_projects WHERE team_id = :team) AS project_count,
                (SELECT COUNT(*) FROM smartlab_samples WHERE team_id = :team) AS sample_count,
                (
                    SELECT COUNT(*)
                    FROM experiments
                    WHERE team = :team
                      AND DATE(date) = CURRENT_DATE()
                ) AS today_experiment_count,
                (
                    SELECT COUNT(*)
                    FROM experiments
                    WHERE team = :team
                      AND DATE(date) = CURRENT_DATE() - INTERVAL 1 DAY
                ) AS yesterday_experiment_count,
                (
                    SELECT COUNT(*)
                    FROM experiments AS e
                    WHERE e.team = :team
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
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetch();
    }

    public function readRecentExperiments(int $limit = 6): array
    {
        $sql = 'SELECT
                e.id,
                e.title,
                e.date,
                e.modified_at,
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
            GROUP BY e.id, p.id, latest_review.status
            ORDER BY e.modified_at DESC, e.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
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
                    '%s #%d 处于 %s 状态。',
                    $review['entity_type'] ?: 'entity',
                    (int) $review['entity_id'],
                    $review['status'] ?: 'submitted',
                ),
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
            );
        }

        return $alerts;
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
        $sql = 'SELECT
                rf.id,
                rf.entity_type,
                rf.entity_id,
                rf.status,
                rf.updated_at,
                p.code AS project_code,
                p.name AS project_name,
                CONCAT(req.firstname, " ", req.lastname) AS requester_name,
                CONCAT(assignee.firstname, " ", assignee.lastname) AS assignee_name,
                rt.decision,
                rt.comment
            FROM smartlab_review_flows AS rf
            LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
            LEFT JOIN users AS req ON req.userid = rf.requester_userid
            LEFT JOIN smartlab_review_tasks AS rt ON rt.flow_id = rf.id
            LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
            WHERE (p.team_id = :team OR (p.team_id IS NULL AND rf.requester_userid IN (
                SELECT users_id FROM users2teams WHERE teams_id = :team
            )))
              AND rf.status IN ("submitted", "needs_revision")
            ORDER BY rf.updated_at DESC, rf.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readRecentSampleEvents(int $limit = 6): array
    {
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
            ORDER BY se.occurred_at DESC, se.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
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

        return $req->fetchAll();
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

    public function readMyReviews(int $limit = 6): array
    {
        $sql = 'SELECT
                rf.id,
                rf.entity_type,
                rf.entity_id,
                rf.status,
                rf.updated_at,
                p.code AS project_code,
                p.name AS project_name,
                CONCAT(assignee.firstname, " ", assignee.lastname) AS assignee_name,
                rt.decision,
                rt.comment
            FROM smartlab_review_flows AS rf
            LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
            LEFT JOIN smartlab_review_tasks AS rt ON rt.flow_id = rf.id
            LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
            WHERE rf.requester_userid = :userid
              AND (p.team_id = :team OR p.team_id IS NULL)
            ORDER BY rf.updated_at DESC, rf.id DESC
            LIMIT :limit';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }
}
