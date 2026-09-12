<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class PercentileChartData extends Data
{
    /** @param  list<PercentilePointData>  $points */
    public function __construct(
        public readonly bool $available,
        public readonly array $points,
        public readonly ?string $message,
    ) {}
}
