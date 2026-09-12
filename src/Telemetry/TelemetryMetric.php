<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * The two timing histograms the recorder maintains per dimension value.
 */
enum TelemetryMetric: string
{
    case Runtime = 'runtime';
    case Wait = 'wait';

    public function label(): string
    {
        return match ($this) {
            self::Runtime => 'Execution time',
            self::Wait => 'Wait time',
        };
    }
}
