<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\BulkOperations\Jobs;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationJob;
use DevactionLabs\HorizonNewDawn\FailedJobs\Actions\ClearFailedJobs;

final class ClearFailedJobsJob extends BulkOperationJob
{
    public function __construct(
        public readonly ?string $operationId = null,
    ) {
        parent::__construct();
    }

    public function handle(ClearFailedJobs $clear): void
    {
        $result = $clear->processChunk($this->operationId);

        $this->continueOrComplete(
            $result,
            'clear-failed-jobs',
            new self($result->operationId),
            ['operation_id' => $result->operationId],
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = ['horizon-new-dawn', 'bulk:clear-failed-jobs'];

        if ($this->operationId !== null) {
            $tags[] = 'operation:'.$this->operationId;
        }

        return $tags;
    }
}
