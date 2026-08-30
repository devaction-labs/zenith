<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\BulkOperations\Jobs;

use DevactionLabs\HorizonNewDawn\Batches\Actions\RetryBatch;
use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationJob;

final class RetryBatchJob extends BulkOperationJob
{
    public function __construct(public readonly string $batchId)
    {
        parent::__construct();
    }

    public function handle(RetryBatch $retry): void
    {
        $this->reportCompletion(
            'retry-batch',
            $retry->handle($this->batchId),
            ['batch' => $this->batchId],
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['horizon-new-dawn', 'bulk:retry-batch', 'batch:'.$this->batchId];
    }
}
