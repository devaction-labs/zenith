<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule\Data;

use Spatie\LaravelData\Data;

final class DynamicCronFormData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $expression,
        public readonly string $jobClass,
        /** @var array<string, mixed> */
        public readonly array $payload,
        public readonly ?string $timezone,
    ) {}
}
