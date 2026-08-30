<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\BulkOperations\Jobs;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationJob;
use DevactionLabs\HorizonNewDawn\Monitoring\Actions\RetryFailedJobs;

final class RetryMonitoredFailedJobsJob extends BulkOperationJob
{
    public function __construct(
        public readonly string $tag,
        public readonly ?string $operationId = null,
    ) {
        parent::__construct();
    }

    public function handle(RetryFailedJobs $retry): void
    {
        $result = $retry->processChunk($this->tag, $this->operationId);

        $this->continueOrComplete(
            $result,
            'retry-monitored-failed-jobs',
            new self($this->tag, $result->operationId),
            [
                'tag' => $this->tag,
                'operation_id' => $result->operationId,
            ],
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = ['horizon-new-dawn', 'bulk:retry-monitored-failed-jobs', 'tag:'.$this->tag];

        if ($this->operationId !== null) {
            $tags[] = 'operation:'.$this->operationId;
        }

        return $tags;
    }
}
