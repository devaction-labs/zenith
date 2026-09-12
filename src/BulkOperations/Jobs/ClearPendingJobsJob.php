<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\BulkOperations\Jobs;

use DevactionLabs\Zenith\BulkOperations\BulkOperationJob;
use DevactionLabs\Zenith\Jobs\Actions\ClearPendingJobs;

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
        return ['zenith', 'bulk:clear-pending-jobs'];
    }
}
