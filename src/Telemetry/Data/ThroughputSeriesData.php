<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class ThroughputSeriesData extends Data
{
    /** @param  list<ThroughputSeriesPointData>  $points */
    public function __construct(
        public readonly string $label,
        public readonly array $points,
    ) {}
}
