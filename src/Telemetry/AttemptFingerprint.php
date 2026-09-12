<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

use Throwable;

/**
 * A short, stable identifier for where an exception was thrown, without
 * exposing its message or full stack trace.
 *
 * Grouping attempts by this fingerprint (rather than by message, which
 * often embeds request-specific data) lets an operator recognize "this is
 * the same failure as last time" across a job's attempt history.
 */
final class AttemptFingerprint
{
    public static function for(Throwable $exception): string
    {
        return substr(hash('crc32b', $exception->getFile().':'.$exception->getLine()), 0, 8);
    }
}
