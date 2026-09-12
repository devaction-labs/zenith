<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows\Data;

use Spatie\LaravelData\Data;

final class WorkflowStepData extends Data
{
    /**
     * @param  list<string>  $deps
     * @param  array<string, mixed>|null  $output
     */
    public function __construct(
        public readonly string $name,
        public readonly string $jobClass,
        public readonly array $deps,
        public readonly bool $cascade,
        public readonly string $status,
        public readonly ?array $output,
        public readonly ?string $error,
        public readonly int $attempts,
        public readonly ?float $finishedAt,
        public readonly bool $nested,
        public readonly ?string $childId,
        public readonly bool $stale,
    ) {}
}
