<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows\Data;

use Spatie\LaravelData\Data;

final class WorkflowDetailData extends Data
{
    /**
     * @param  list<WorkflowStepData>  $steps
     * @param  array<string, mixed>  $context
     * @param  list<WorkflowRowData>  $children
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly string $status,
        public readonly array $context,
        public readonly array $steps,
        public readonly ?float $createdAt,
        public readonly ?float $finishedAt,
        public readonly bool $cancellable,
        public readonly bool $retryable,
        public readonly ?string $parentId,
        public readonly array $children,
    ) {}
}
