<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Supervisors\Data;

use Spatie\LaravelData\Data;

final class SupervisorScaleBoundsData extends Data
{
    public function __construct(
        public readonly int $min,
        public readonly int $max,
    ) {}
}
