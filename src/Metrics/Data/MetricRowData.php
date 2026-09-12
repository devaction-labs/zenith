<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Metrics\Data;

use Spatie\LaravelData\Data;

final class MetricRowData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly int $throughput,
        public readonly float $runtime,
    ) {}
}
