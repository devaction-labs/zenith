<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows\Data;

use Spatie\LaravelData\Data;

final class WorkflowRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly string $status,
        public readonly int $stepCount,
        public readonly int $completedSteps,
        public readonly ?float $createdAt,
        public readonly ?float $finishedAt,
    ) {}
}
