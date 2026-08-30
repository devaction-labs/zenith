<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\BulkOperations\Jobs;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationJob;
use DevactionLabs\HorizonNewDawn\Jobs\Actions\ClearPendingJobs;

final class ClearPendingJobsJob extends BulkOperationJob
{
    public function handle(ClearPendingJobs $clear): void
    {
        $result = $clear->handle();

        $this->reportCompletion('clear-pending-jobs', $result->cleared, [
            'failed_targets' => $result->failedTargets,
        ]);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['horizon-new-dawn', 'bulk:clear-pending-jobs'];
    }
}
