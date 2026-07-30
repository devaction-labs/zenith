<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\BulkOperations\Jobs;

use NckRtl\HorizonNewDawn\Batches\Actions\ClearBatchFailedJobs;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationJob;

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
        return ['horizon-new-dawn', 'bulk:clear-batch-failed-jobs', 'batch:'.$this->batchId];
    }
}
