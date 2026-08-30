<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Support\Data;

use DevactionLabs\HorizonNewDawn\Authorization\HorizonAbilitiesData;
use DevactionLabs\HorizonNewDawn\Dashboard\HorizonStatus;
use DevactionLabs\HorizonNewDawn\Support\FrameworkCapabilities;
use Spatie\LaravelData\Data;

final class HorizonShellData extends Data
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly int $pollInterval,
        public readonly HorizonStatus $status,
        public readonly bool $processing,
        public readonly bool $maintenanceMode,
        public readonly FrameworkCapabilities $capabilities,
        public readonly bool $jobNavigationBreakdown = false,
        public readonly bool $allQueuesPaused = false,
        public readonly ?HorizonAbilitiesData $abilities = null,
    ) {}
}
