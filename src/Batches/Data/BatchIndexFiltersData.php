<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches\Data;

use DevactionLabs\Zenith\Batches\BatchCreatedRange;
use DevactionLabs\Zenith\Batches\BatchSort;
use DevactionLabs\Zenith\Batches\BatchSortDirection;
use DevactionLabs\Zenith\Batches\BatchStatus;
use Spatie\LaravelData\Data;

final class BatchIndexFiltersData extends Data
{
    public function __construct(
        public readonly ?string $query,
        public readonly ?string $queue,
        public readonly ?string $connection,
        public readonly ?BatchCreatedRange $created,
        public readonly ?BatchStatus $status = null,
        public readonly BatchSort $sort = BatchSort::CreatedAt,
        public readonly BatchSortDirection $direction = BatchSortDirection::Descending,
    ) {}
}
