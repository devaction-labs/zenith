<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class PercentilePointData extends Data
{
    public function __construct(
        public readonly int $timestamp,
        public readonly ?int $p50,
        public readonly ?int $p95,
        public readonly ?int $p99,
    ) {}
}
