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
use Elabftw\Exceptions\UnauthorizedException;
use Elabftw\Models\Users\Users;
use PDO;

final class SmartlabReviews extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
        new SmartlabBenchRecords($Users);
    }

    public function readAll(bool $mineOnly = false, bool $history = false): array
    {
        $userid = (int) $this->Users->userid;
        $mineSql = $mineOnly ? ' AND rt.assignee_userid = ' . $userid : '';
        $latestSql = $history ? '' : ' AND rf.id = (SELECT MAX(newer.id) FROM smartlab_review_flows newer WHERE newer.entity_type = rf.entity_type AND newer.entity_id = rf.entity_id)';
        $sql = 'SELECT
                rf.id,
                rf.entity_type,
                rf.entity_id,
                e.title AS experiment_title,
                (SELECT JSON_UNQUOTE(JSON_EXTRACT(b.payload, "$.mode")) FROM smartlab_bench_records b WHERE b.experiment_id=rf.entity_id AND b.record_key=CONCAT("submission.",rf.id) ORDER BY b.revision DESC LIMIT 1) AS bench_mode,
                rf.status,
                rf.created_at,
                p.code AS project_code,
                p.name AS project_name,
                CONCAT(req.firstname, " ", req.lastname) AS requester_name,
                CONCAT(assignee.firstname, " ", assignee.lastname) AS assignee_name,
                rt.id AS task_id,
                rt.assignee_userid,
                rt.decision,
                rt.decided_at,
                rt.comment
            FROM smartlab_review_flows AS rf
            LEFT JOIN experiments AS e ON rf.entity_type = "experiment" AND e.id = rf.entity_id
            LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
            LEFT JOIN users AS req ON req.userid = rf.requester_userid
            LEFT JOIN smartlab_review_tasks AS rt ON rt.id = (SELECT t.id FROM smartlab_review_tasks t WHERE t.flow_id = rf.id ORDER BY (t.assignee_userid = ' . $userid . ') DESC, t.id DESC LIMIT 1)
            LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
            WHERE (p.team_id = :team OR (p.team_id IS NULL AND req.userid IN (
                SELECT users_id FROM users2teams WHERE teams_id = :team
            )))
            ' . $mineSql . $latestSql . '
            ORDER BY rf.updated_at DESC, rf.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    /**
     * @throws ResourceNotFoundException
     * @throws UnauthorizedException
     */
    public function applyDecision(int $flowId, string $decision, string $comment): void
    {
        $task = $this->getTaskForUpdate($flowId);
        if (mb_strlen($comment) > 4000 || ($decision !== 'approve' && trim($comment) === '')) { throw new \Elabftw\Exceptions\AppException('请填写具体反馈，最多 4000 个字符。'); }
        if ((int) $task['assignee_userid'] !== (int) $this->Users->userid && !$this->Users->isAdmin) {
            throw new UnauthorizedException();
        }

        $decisionMap = array(
            'approve' => array('task' => 'approved', 'flow' => 'approved'),
            'request_changes' => array('task' => 'changes_requested', 'flow' => 'needs_revision'),
            'reject' => array('task' => 'rejected', 'flow' => 'rejected'),
        );

        if (!isset($decisionMap[$decision])) {
            return;
        }

        $taskDecision = $decisionMap[$decision]['task'];
        $flowStatus = $decisionMap[$decision]['flow'];

        $sql = 'UPDATE smartlab_review_tasks
            SET decision = :decision,
                comment = :comment,
                decided_at = NOW()
            WHERE id = :task_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':decision', $taskDecision);
        $req->bindValue(':comment', $comment);
        $req->bindValue(':task_id', $task['task_id'], PDO::PARAM_INT);
        $this->Db->execute($req);

        $sql = 'UPDATE smartlab_review_flows
            SET status = :status,
                updated_at = NOW()
            WHERE id = :flow_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':status', $flowStatus);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $this->Db->execute($req);

        $materialStatus = match ($flowStatus) {
            'approved' => 'approved',
            'needs_revision' => 'needs_revision',
            'rejected' => 'rejected',
            default => $flowStatus,
        };
        $sql = 'UPDATE smartlab_experiment_report_materials
            SET status = :status,
                mentor_feedback_action = :feedback_action,
                mentor_feedback = :feedback,
                feedback_by = :feedback_by,
                feedback_at = NOW(),
                updated_at = NOW()
            WHERE review_flow_id = :flow_id
              AND team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':status', $materialStatus);
        $req->bindValue(':feedback_action', $decision);
        $req->bindValue(':feedback', $comment !== '' ? $comment : null);
        $req->bindValue(':feedback_by', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        if ($task['entity_type'] === 'experiment') { (new SmartlabBenchRecords($this->Users))->recordFeedback((int) $task['entity_id'], $flowId, $decision, $comment); }
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function getTaskForUpdate(int $flowId): array
    {
        $sql = 'SELECT
                rt.id AS task_id,
                rt.assignee_userid,
                rf.id AS flow_id,
                rf.entity_id,
                rf.entity_type
            FROM smartlab_review_tasks AS rt
            INNER JOIN smartlab_review_flows AS rf ON rf.id = rt.flow_id
            LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
            WHERE rt.flow_id = :flow_id
              AND (p.team_id = :team OR (p.team_id IS NULL AND rf.requester_userid = :userid))
            ORDER BY (rt.assignee_userid = :preferred_userid) DESC, rt.id DESC
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':preferred_userid', $this->Users->userid, PDO::PARAM_INT);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $this->Db->fetch($req);
    }
}
