<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule\Data;

use Spatie\LaravelData\Data;

final class ScheduleRunData extends Data
{
    public function __construct(
        public readonly string $status,
        public readonly float $startedAt,
        public readonly ?float $durationMs,
        public readonly ?int $exitCode,
        public readonly ?string $outputTail,
    ) {}
}
