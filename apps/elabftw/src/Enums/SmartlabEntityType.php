<?php

declare(strict_types=1);

namespace Elabftw\Enums;

enum SmartlabEntityType: string
{
    case Experiment = 'experiment';
    case Sample = 'sample';
    case Item = 'item';
}
