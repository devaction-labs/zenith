<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class ThroughputSeriesPointData extends Data
{
    public function __construct(
        public readonly int $timestamp,
        public readonly int $count,
    ) {}
}
