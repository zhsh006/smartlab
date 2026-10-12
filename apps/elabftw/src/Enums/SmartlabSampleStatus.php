<?php

declare(strict_types=1);

namespace Elabftw\Enums;

enum SmartlabSampleStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Depleted = 'depleted';
}
