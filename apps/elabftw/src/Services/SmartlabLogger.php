<?php

declare(strict_types=1);

namespace Elabftw\Services;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * SmartLab structured logger.
 *
 * Outputs to error_log with [SmartLab] prefix and JSON context.
 * Use in controllers and models for debugging and error tracking.
 */
final class SmartlabLogger extends AbstractLogger
{
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $logEntry = sprintf(
            '[%s] SmartLab.%s: %s %s',
            date('c'),
            strtoupper((string) $level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        );
        error_log($logEntry);
    }
}
