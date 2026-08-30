<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Authorization;

use Spatie\LaravelData\Data;

final class HorizonAbilitiesData extends Data
{
    public function __construct(
        public readonly bool $pauseQueues,
        public readonly bool $clearQueues,
        public readonly bool $retryJobs,
        public readonly bool $cancelJobs,
        public readonly bool $manageInstances,
        public readonly bool $manageMonitoring,
        public readonly bool $manageBatches,
    ) {}

    public static function allowAll(): self
    {
        return new self(
            pauseQueues: true,
            clearQueues: true,
            retryJobs: true,
            cancelJobs: true,
            manageInstances: true,
            manageMonitoring: true,
            manageBatches: true,
        );
    }
}
