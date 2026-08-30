<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Dashboard\Data;

use DevactionLabs\HorizonNewDawn\Queues\Data\QueueWaitThresholdData;
use Spatie\LaravelData\Data;

final class WorkloadItemData extends Data
{
    /** @param  null|array<int, WorkloadSplitData>  $splitQueues */
    public function __construct(
        public readonly string $name,
        public readonly string $connection,
        public readonly int $length,
        public readonly int|float $wait,
        public readonly int $processes,
        public readonly bool $processesShared,
        public readonly bool $paused,
        public readonly ?int $pausedUntil,
        public readonly ?int $throughput,
        public readonly ?array $splitQueues,
        public readonly QueueWaitThresholdData $waitThreshold,
    ) {}
}
