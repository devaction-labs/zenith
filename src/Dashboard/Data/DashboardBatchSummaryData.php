<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Dashboard\Data;

use Spatie\LaravelData\Data;

final class DashboardBatchSummaryData extends Data
{
    /** @param array<int, DashboardBatchPreviewData> $previews */
    public function __construct(
        public readonly bool $batchesAvailable,
        public readonly ?int $active,
        public readonly array $previews,
    ) {}
}
