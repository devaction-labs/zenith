<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\BulkOperations\Jobs;

use DevactionLabs\Zenith\BulkOperations\BulkOperationJob;
use DevactionLabs\Zenith\FailedJobs\Actions\RetryAllFailedJobs;

final class RetryAllFailedJobsJob extends BulkOperationJob
{
    public function __construct(
        public readonly ?string $connectionName = null,
        public readonly ?string $queueName = null,
        public readonly ?string $operationId = null,
    ) {
        parent::__construct();
    }

    public function handle(RetryAllFailedJobs $retry): void
    {
        $result = $retry->processChunk(
            $this->operationId,
            $this->connectionName,
            $this->queueName,
        );

        $this->continueOrComplete(
            $result,
            'retry-failed-jobs',
            new self(
                $this->connectionName,
                $this->queueName,
                $result->operationId,
            ),
            array_filter([
                'connection' => $this->connectionName,
                'queue' => $this->queueName,
                'operation_id' => $result->operationId,
            ], static fn (?string $value): bool => $value !== null),
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = ['zenith', 'bulk:retry-failed-jobs'];

        if ($this->connectionName !== null) {
            $tags[] = 'connection:'.$this->connectionName;
        }

        if ($this->queueName !== null) {
            $tags[] = 'queue:'.$this->queueName;
        }

        if ($this->operationId !== null) {
            $tags[] = 'operation:'.$this->operationId;
        }

        return $tags;
    }
}
