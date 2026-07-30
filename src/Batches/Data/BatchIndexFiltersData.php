<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Batches\Data;

use NckRtl\HorizonNewDawn\Batches\BatchCreatedRange;
use NckRtl\HorizonNewDawn\Batches\BatchSort;
use NckRtl\HorizonNewDawn\Batches\BatchSortDirection;
use NckRtl\HorizonNewDawn\Batches\BatchStatus;
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
