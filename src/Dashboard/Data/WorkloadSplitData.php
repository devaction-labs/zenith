<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Dashboard\Data;

use DevactionLabs\HorizonNewDawn\Queues\Data\QueueWaitThresholdData;
use Spatie\LaravelData\Data;

final class WorkloadSplitData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly int $length,
        public readonly int|float $wait,
        public readonly bool $paused,
        public readonly ?int $pausedUntil,
        public readonly ?int $throughput,
        public readonly QueueWaitThresholdData $waitThreshold,
    ) {}
}
