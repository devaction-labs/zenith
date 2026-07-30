<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\BulkOperations\Jobs;

use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationJob;
use NckRtl\HorizonNewDawn\Monitoring\Actions\ClearRecentJobs;

final class ClearRecentJobsJob extends BulkOperationJob
{
    public function __construct(
        public readonly string $tag,
        public readonly ?string $operationId = null,
    ) {
        parent::__construct();
    }

    public function handle(ClearRecentJobs $clear): void
    {
        $result = $clear->processChunk($this->tag, $this->operationId);

        $this->continueOrComplete(
            $result,
            'clear-recent-jobs',
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
        $tags = ['horizon-new-dawn', 'bulk:clear-recent-jobs', 'tag:'.$this->tag];

        if ($this->operationId !== null) {
            $tags[] = 'operation:'.$this->operationId;
        }

        return $tags;
    }
}
