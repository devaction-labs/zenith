<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Batches\Data;

use Spatie\LaravelData\Data;

final class BatchStatusCountsData extends Data
{
    public function __construct(
        public readonly int $all,
        public readonly int $pending,
        public readonly int $finished,
        public readonly int $failures,
        public readonly int $cancelled,
    ) {}

    public static function zero(): self
    {
        return new self(
            all: 0,
            pending: 0,
            finished: 0,
            failures: 0,
            cancelled: 0,
        );
    }
}
