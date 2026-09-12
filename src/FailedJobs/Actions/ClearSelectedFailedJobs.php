<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\FailedJobs\Actions;

use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\Zenith\BulkOperations\Jobs\ClearFailedJobsJob;

final readonly class ClearSelectedFailedJobs
{
    public function __construct(
        private BulkOperationSnapshot $snapshots,
        private BulkOperationDispatcher $dispatcher,
    ) {}

    /**
     * Permanently remove an explicit, client-selected set of failed job ids.
     *
     * @param  list<string>  $ids
     */
    public function handle(array $ids): void
    {
        $operationId = $this->snapshots->createFromIds($ids);

        $this->dispatcher->dispatch(new ClearFailedJobsJob($operationId));
    }
}
