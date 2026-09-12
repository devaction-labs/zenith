<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryBatchJob;
use Illuminate\Bus\BatchRepository;
use Laravel\Horizon\Http\Controllers\BatchesController as HorizonBatchesController;

final class BatchesApiController extends HorizonBatchesController
{
    public function __construct(
        BatchRepository $batches,
        private readonly BulkOperationDispatcher $dispatcher,
    ) {
        parent::__construct($batches);
    }

    public function retry(mixed $id): void
    {
        $this->dispatcher->dispatch(new RetryBatchJob((string) $id));
    }
}
