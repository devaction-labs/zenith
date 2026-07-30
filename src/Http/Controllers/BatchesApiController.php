<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Http\Controllers;

use Illuminate\Bus\BatchRepository;
use Laravel\Horizon\Http\Controllers\BatchesController as HorizonBatchesController;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\RetryBatchJob;

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
