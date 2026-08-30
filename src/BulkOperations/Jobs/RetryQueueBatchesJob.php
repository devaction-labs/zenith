<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\BulkOperations\Jobs;

use DevactionLabs\HorizonNewDawn\Batches\Actions\RetryQueueBatches;
use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationJob;

final class RetryQueueBatchesJob extends BulkOperationJob
{
    public function __construct(
        public readonly string $queueName,
        public readonly ?string $operationId = null,
    ) {
        parent::__construct();
    }

    public function handle(RetryQueueBatches $retry): void
    {
        $result = $retry->processChunk($this->queueName, $this->operationId);

        $this->continueOrComplete(
            $result,
            'retry-queue-batches',
            new self($this->queueName, $result->operationId),
            [
                'queue' => $this->queueName,
                'operation_id' => $result->operationId,
            ],
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = ['horizon-new-dawn', 'bulk:retry-queue-batches', 'queue:'.$this->queueName];

        if ($this->operationId !== null) {
            $tags[] = 'operation:'.$this->operationId;
        }

        return $tags;
    }
}
