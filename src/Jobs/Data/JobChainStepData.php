<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Data;

use Spatie\LaravelData\Data;

final class JobChainStepData extends Data
{
    public function __construct(
        public readonly string $class,
    ) {}
}
