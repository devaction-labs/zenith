<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class ThroughputChartData extends Data
{
    /** @param  list<ThroughputSeriesData>  $series */
    public function __construct(
        public readonly bool $available,
        public readonly array $series,
        public readonly ?string $message,
    ) {}
}
