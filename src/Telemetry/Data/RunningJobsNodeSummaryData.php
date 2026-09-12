<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class RunningJobsNodeSummaryData extends Data
{
    public function __construct(
        public readonly string $node,
        public readonly int $count,
    ) {}
}
