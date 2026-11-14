<?php

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Elabftw\Db;
use Elabftw\Models\Users\Users;

/**
 * Base class for all SmartLab custom models.
 * Provides shared Db connection and Users dependency.
 */
abstract class AbstractSmartlabModel
{
    protected Db $Db;

    public function __construct(protected readonly Users $Users)
    {
        $this->Db = Db::getConnection();
    }
}
