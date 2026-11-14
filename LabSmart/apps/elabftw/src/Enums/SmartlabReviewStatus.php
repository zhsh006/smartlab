<?php

declare(strict_types=1);

namespace Elabftw\Enums;

enum SmartlabReviewStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case NeedsRevision = 'needs_revision';
}
