<?php

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Models\Users\Users;
use PDO;

final class SmartlabReviewFlows extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
    }

    /**
     * Find an existing open review flow for an experiment and requester.
     */
    public function findExisting(int $experimentId, int $requesterUserId): ?int
    {
        $sql = 'SELECT id
            FROM smartlab_review_flows
            WHERE entity_type = "experiment"
              AND entity_id = :experiment_id
              AND requester_userid = :userid
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':experiment_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':userid', $requesterUserId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $flowId = $req->fetchColumn();
        if ($flowId === false) {
            return null;
        }
        return (int) $flowId;
    }

    /**
     * Create a new review flow. Returns the new flow ID.
     */
    public function createFlow(?int $projectId, int $experimentId, int $requesterUserId): int
    {
        $sql = 'INSERT INTO smartlab_review_flows
            (project_id, entity_type, entity_id, status, requester_userid)
            VALUES (:project_id, "experiment", :entity_id, "submitted", :userid)';
        $req = $this->Db->prepare($sql);
        if ($projectId === null || $projectId <= 0) {
            $req->bindValue(':project_id', null, PDO::PARAM_NULL);
        } else {
            $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        }
        $req->bindValue(':entity_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':userid', $requesterUserId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $this->Db->lastInsertId();
    }

    /**
     * Re-submit an existing flow (set status to "submitted").
     */
    public function resubmitFlow(int $flowId): void
    {
        $sql = 'UPDATE smartlab_review_flows
            SET status = "submitted", updated_at = CURRENT_TIMESTAMP
            WHERE id = :flow_id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * Find the linked project ID for an experiment.
     */
    public function findLinkedProjectId(int $experimentId): ?int
    {
        $sql = 'SELECT project_id
            FROM smartlab_project_entities
            WHERE entity_type = "experiment"
              AND entity_id = :id
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':id', $experimentId, PDO::PARAM_INT);
        $this->Db->execute($req);
        $projectId = $req->fetchColumn();
        if ($projectId === false) {
            return null;
        }
        return (int) $projectId;
    }

    /**
     * Find a team reviewer user ID (admin or owner, not the requester).
     */
    public function findTeamReviewerUserId(): ?int
    {
        $sql = 'SELECT users_id
            FROM users2teams
            WHERE teams_id = :team
              AND users_id <> :userid
              AND (is_owner = 1 OR is_admin = 1)
            ORDER BY is_owner DESC, is_admin DESC, users_id ASC
            LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':userid', $this->Users->userid, PDO::PARAM_INT);
        $this->Db->execute($req);
        $userId = $req->fetchColumn();
        if ($userId === false) {
            return null;
        }
        return (int) $userId;
    }

    /**
     * Create a review task assigned to a reviewer.
     */
    public function createReviewTask(int $flowId, int $assigneeUserId, string $comment): void
    {
        $sql = 'INSERT INTO smartlab_review_tasks
            (flow_id, assignee_userid, comment)
            VALUES (:flow_id, :assignee, :comment)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':flow_id', $flowId, PDO::PARAM_INT);
        $req->bindValue(':assignee', $assigneeUserId, PDO::PARAM_INT);
        $req->bindValue(':comment', $comment);
        $this->Db->execute($req);
    }
}
