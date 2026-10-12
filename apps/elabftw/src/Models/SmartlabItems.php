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

final class SmartlabItems extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
    }

    public function readProjects(int $itemId): array
    {
        $sql = 'SELECT
                pe.id AS link_id,
                p.id AS project_id,
                p.code,
                p.name,
                p.status,
                p.updated_at
            FROM smartlab_project_entities AS pe
            INNER JOIN smartlab_projects AS p ON p.id = pe.project_id
            INNER JOIN items AS i ON i.id = pe.entity_id
            WHERE pe.entity_type = "item"
              AND pe.entity_id = :item_id
              AND p.team_id = :team
              AND i.team = :team
            ORDER BY p.updated_at DESC, p.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readAvailableProjects(int $itemId): array
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
                  AND pe.entity_type = "item"
                  AND pe.entity_id = :item_id
              )
            ORDER BY p.updated_at DESC, p.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $req->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readLinkedExperiments(int $itemId): array
    {
        $sql = 'SELECT
                e.id,
                e.title,
                e.date,
                e.modified_at,
                p.id AS project_id,
                p.code AS project_code,
                p.name AS project_name
            FROM items2experiments AS i2e
            INNER JOIN items AS i ON i.id = i2e.item_id
            INNER JOIN experiments AS e ON e.id = i2e.link_id
            LEFT JOIN smartlab_project_entities AS pe
                ON pe.entity_type = "experiment"
               AND pe.entity_id = e.id
            LEFT JOIN smartlab_projects AS p
                ON p.id = pe.project_id
               AND p.team_id = i.team
            WHERE i2e.item_id = :item_id
              AND i.team = :team
              AND e.team = :team
            GROUP BY e.id, p.id
            ORDER BY e.modified_at DESC, e.id DESC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetchAll();
    }

    public function readStorageSummary(int $itemId): array
    {
        $sql = 'SELECT
                COUNT(DISTINCT c2i.id) AS container_count,
                SUM(c2i.qty_stored) AS total_qty,
                MAX(CASE WHEN c2i.qty_stored <= 2 THEN 1 ELSE 0 END) AS has_low_stock
            FROM containers2items AS c2i
            INNER JOIN items AS i ON i.id = c2i.item_id
            WHERE c2i.item_id = :item_id
              AND i.team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        return $req->fetch();
    }

    public function linkProject(int $itemId, int $projectId): void
    {
        $this->assertItemInTeam($itemId);
        $this->assertProjectInTeam($projectId);

        $sql = 'INSERT IGNORE INTO smartlab_project_entities (project_id, entity_type, entity_id)
            VALUES (:project_id, "item", :item_id)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    public function unlinkProject(int $itemId, int $linkId): void
    {
        $this->assertItemInTeam($itemId);

        $sql = 'DELETE pe
            FROM smartlab_project_entities AS pe
            INNER JOIN smartlab_projects AS p ON p.id = pe.project_id
            WHERE pe.id = :link_id
              AND pe.entity_type = "item"
              AND pe.entity_id = :item_id
              AND p.team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':link_id', $linkId, PDO::PARAM_INT);
        $req->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    private function assertItemInTeam(int $itemId): void
    {
        $sql = 'SELECT id
            FROM items
            WHERE id = :item_id
              AND team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $this->Db->fetch($req);
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function assertProjectInTeam(int $projectId): void
    {
        $sql = 'SELECT id
            FROM smartlab_projects
            WHERE id = :project_id
              AND team_id = :team';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $req->bindValue(':team', $this->Users->team, PDO::PARAM_INT);
        $this->Db->execute($req);
        $this->Db->fetch($req);
    }
}
