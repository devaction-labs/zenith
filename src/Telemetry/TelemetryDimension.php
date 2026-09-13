<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * The three axes counters and histograms are grouped by.
 *
 * Every recorded attempt increments one field per dimension, so a global
 * total for any dimension is the sum across its own values rather than a
 * separately stored series (see `TelemetryRecorder`).
 */
enum TelemetryDimension: string
{
    case Queue = 'queue';
    case JobClass = 'class';
    case Node = 'node';
    case Connection = 'connection';

    public function label(): string
    {
        return match ($this) {
            self::Queue => 'Queue',
            self::JobClass => 'Job class',
            self::Node => 'Node',
            self::Connection => 'Connection',
        };
    }
}
