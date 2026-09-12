<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class RunningJobData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $jobClass,
        public readonly string $node,
        public readonly ?string $supervisor,
        public readonly int $startedAt,
        public readonly int $elapsedSeconds,
        public readonly ?int $timeoutSeconds,
        public readonly bool $overrunning,
    ) {}
}
