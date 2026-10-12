<?php

declare(strict_types=1);

namespace Elabftw\Enums;

enum SmartlabSampleEventType: string
{
    case ExperimentRecorded = 'experiment_recorded';
    case UsedInExperiment = 'used_in_experiment';
    case Aliquoted = 'aliquoted';
    case Consumed = 'consumed';
    case Received = 'received';
    case Disposed = 'disposed';
}
