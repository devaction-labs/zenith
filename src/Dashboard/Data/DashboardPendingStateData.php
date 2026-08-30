<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Dashboard\Data;

use Spatie\LaravelData\Data;

final class DashboardPendingStateData extends Data
{
    public function __construct(
        public readonly ?int $reserved,
        public readonly ?int $readyNow,
        public readonly ?int $delayed,
    ) {}
}
