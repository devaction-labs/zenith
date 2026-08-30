<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches\Data;

use DevactionLabs\HorizonNewDawn\Batches\BatchCreatedRange;
use DevactionLabs\HorizonNewDawn\Batches\BatchSort;
use DevactionLabs\HorizonNewDawn\Batches\BatchSortDirection;
use DevactionLabs\HorizonNewDawn\Batches\BatchStatus;
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
