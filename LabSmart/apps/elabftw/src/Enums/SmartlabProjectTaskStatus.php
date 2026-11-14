<?php

declare(strict_types=1);

namespace Elabftw\Enums;

enum SmartlabProjectTaskStatus: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Blocked = 'blocked';
    case Done = 'done';
}
