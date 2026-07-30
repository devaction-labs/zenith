<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Jobs;

use Illuminate\Support\Collection;

final readonly class RetainedJobQueryPage
{
    /** @param Collection<int, \stdClass> $jobs */
    public function __construct(
        public Collection $jobs,
        public int $total,
        public int|string|null $current,
        public ?string $next,
    ) {}
}
