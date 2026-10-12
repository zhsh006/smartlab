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
    }

    public function readAll(): array
    {
        $sql = 'SELECT
                rf.id,
                rf.entity_type,
                rf.entity_id,
                rf.status,
                rf.created_at,
                p.code AS project_code,
                p.name AS project_name,
                CONCAT(req.firstname, " ", req.lastname) AS requester_name,
                CONCAT(assignee.firstname, " ", assignee.lastname) AS assignee_name,
                rt.decision,
                rt.decided_at,
                rt.comment
            FROM smartlab_review_flows AS rf
            LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
            LEFT JOIN users AS req ON req.userid = rf.requester_userid
            LEFT JOIN smartlab_review_tasks AS rt ON rt.flow_id = rf.id
            LEFT JOIN users AS assignee ON assignee.userid = rt.assignee_userid
            WHERE p.team_id = :team OR (p.team_id IS NULL AND req.userid IN (
                SELECT users_id FROM users2teams WHERE teams_id = :team
            ))
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
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function getTaskForUpdate(int $flowId): array
    {
        $sql = 'SELECT
                rt.id AS task_id,
                rt.assignee_userid,
                rf.id AS flow_id
            FROM smartlab_review_tasks AS rt
            INNER JOIN smartlab_review_flows AS rf ON rf.id = rt.flow_id
            LEFT JOIN smartlab_projects AS p ON p.id = rf.project_id
            WHERE rt.flow_id = :flow_id
              AND (p.team_id = :team OR (p.team_id IS NULL AND rf.requester_userid = :userid))
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $this->Db->fetch($req);
    }
}
