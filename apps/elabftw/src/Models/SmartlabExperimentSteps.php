<?php

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Models\Users\Users;
use PDO;

final class SmartlabExperimentSteps extends AbstractSmartlabModel
{
    public function __construct(Users $Users)
    {
        parent::__construct($Users);
    }

    public function createStep(int $experimentId, string $body, int $ordering): void
    {
        $sql = 'INSERT INTO experiments_steps (item_id, body, ordering)
            VALUES (:item_id, :body, :ordering)';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':item_id', $experimentId, PDO::PARAM_INT);
        $req->bindValue(':body', $body);
        $req->bindValue(':ordering', $ordering, PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * @param string[] $steps
     */
    public function insertSteps(int $experimentId, array $steps): void
    {
        $ordering = 1;
        foreach ($steps as $step) {
            $body = trim((string) $step);
            if ($body === '') {
                continue;
            }
            $this->createStep(
                $experimentId,
                htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $ordering++,
            );
        }
    }
}
