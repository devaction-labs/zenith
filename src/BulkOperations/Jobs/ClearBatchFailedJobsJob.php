<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\BulkOperations\Jobs;

use DevactionLabs\Zenith\Batches\Actions\ClearBatchFailedJobs;
use DevactionLabs\Zenith\BulkOperations\BulkOperationJob;

final class ClearBatchFailedJobsJob extends BulkOperationJob
{
    public function __construct(public readonly string $batchId)
    {
        parent::__construct();
    }

    public function handle(ClearBatchFailedJobs $clear): void
    {
        $this->reportCompletion(
            'clear-batch-failed-jobs',
            $clear->handle($this->batchId),
            ['batch' => $this->batchId],
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['zenith', 'bulk:clear-batch-failed-jobs', 'batch:'.$this->batchId];
    }
}
