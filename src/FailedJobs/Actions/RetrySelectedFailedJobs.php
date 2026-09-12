<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\FailedJobs\Actions;

use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryAllFailedJobsJob;

final readonly class RetrySelectedFailedJobs
{
    public function __construct(
        private BulkOperationSnapshot $snapshots,
        private BulkOperationDispatcher $dispatcher,
    ) {}

    /**
     * Retry an explicit, client-selected set of failed job ids.
     *
     * Reuses the existing failed-job retry job so unique-job locks, debounce
     * contracts, and retry-eligibility rules are still enforced per job.
     *
     * @param  list<string>  $ids
     */
    public function handle(array $ids): void
    {
        $operationId = $this->snapshots->createFromIds($ids);

        $this->dispatcher->dispatch(new RetryAllFailedJobsJob(operationId: $operationId));
    }
}
