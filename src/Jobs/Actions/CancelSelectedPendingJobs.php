<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Actions;

use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\Zenith\BulkOperations\Jobs\CancelPendingJobsJob;
use DevactionLabs\Zenith\Jobs\PendingJobCancellationScope;

final readonly class CancelSelectedPendingJobs
{
    public function __construct(
        private BulkOperationSnapshot $snapshots,
        private BulkOperationDispatcher $dispatcher,
    ) {}

    /**
     * Cancel an explicit, client-selected set of pending job ids.
     *
     * Reuses the existing scoped cancellation job so batched jobs are still
     * skipped and only cancelled through their batch.
     *
     * @param  list<string>  $ids
     */
    public function handle(array $ids): void
    {
        $operationId = $this->snapshots->createFromIds($ids);

        $this->dispatcher->dispatch(new CancelPendingJobsJob(
            PendingJobCancellationScope::Pending,
            operationId: $operationId,
        ));
    }
}
