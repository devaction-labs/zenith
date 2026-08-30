<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Dashboard;

use DevactionLabs\HorizonNewDawn\Batches\BatchRepositoryOverview;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchCapability;
use DevactionLabs\HorizonNewDawn\Dashboard\Data\DashboardBatchPreviewData;
use DevactionLabs\HorizonNewDawn\Dashboard\Data\DashboardBatchSummaryData;

final readonly class DashboardBatchSummary
{
    public function __construct(
        private BatchRepositoryOverview $batches,
        private DatabaseBatchCapability $capability,
    ) {}

    public function get(): DashboardBatchSummaryData
    {
        if (! $this->capability->available()) {
            return new DashboardBatchSummaryData(
                batchesAvailable: false,
                active: null,
                previews: [],
            );
        }

        $overview = $this->batches->get();

        return new DashboardBatchSummaryData(
            batchesAvailable: true,
            active: $overview['complete'] ? $overview['active'] : null,
            previews: array_map(
                static fn (array $preview): DashboardBatchPreviewData => new DashboardBatchPreviewData(
                    id: $preview['id'],
                    name: $preview['name'],
                    progress: $preview['progress'],
                ),
                $overview['previews'],
            ),
        );
    }
}
