<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Metrics\Data;

use Spatie\LaravelData\Data;

final class MetricsPageData extends Data
{
    /** @param array<int, MetricRowData> $metrics */
    public function __construct(
        public readonly bool $available,
        public readonly array $metrics,
        public readonly ?string $message,
    ) {}
}
