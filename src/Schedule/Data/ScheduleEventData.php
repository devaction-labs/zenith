<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule\Data;

use Spatie\LaravelData\Data;

final class ScheduleEventData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $expression,
        public readonly string $description,
        public readonly ?string $command,
        public readonly ?string $timezone,
        public readonly ?float $nextRunAt,
        public readonly bool $withoutOverlapping,
        public readonly bool $onOneServer,
        public readonly bool $evenInMaintenanceMode,
        public readonly bool $runInBackground,
        public readonly bool $overlapping,
        public readonly bool $runtimeEditable,
        public readonly bool $paused,
        /** @var list<ScheduleRunData> */
        public readonly array $history,
    ) {}
}
