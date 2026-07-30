<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Jobs;

final readonly class RetainedJobPosition
{
    public function __construct(
        public ?float $score,
        public string $id,
        public int $offset,
    ) {}
}
