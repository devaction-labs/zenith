<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\BulkOperations\Jobs;

use DevactionLabs\Zenith\Batches\Actions\RetryBatch;
use DevactionLabs\Zenith\BulkOperations\BulkOperationJob;

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
        return ['zenith', 'bulk:retry-batch', 'batch:'.$this->batchId];
    }
}
