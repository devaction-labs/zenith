<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\BulkOperations\Jobs;

use DevactionLabs\Zenith\BulkOperations\BulkOperationJob;
use DevactionLabs\Zenith\Jobs\Actions\CancelPendingJobs;
use DevactionLabs\Zenith\Jobs\PendingJobCancellationScope;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

final class CancelPendingJobsJob extends BulkOperationJob
{
    public function __construct(
        public readonly PendingJobCancellationScope $scope,
        public readonly ?string $queueName = null,
        public readonly ?string $operationId = null,
        public readonly int $batched = 0,
        public readonly int $failed = 0,
    ) {
        parent::__construct();
    }

    public function handle(CancelPendingJobs $cancel): void
    {
        $result = $cancel->processChunk(
            $this->scope,
            $this->queueName,
            $this->operationId,
        );

        $batched = $this->batched + $result->chunkBatched;
        $failed = $this->failed + $result->chunkFailed;

        if (! $result->complete) {
            Bus::dispatch(new self(
                $this->scope,
                $this->queueName,
                $result->operationId,
                $batched,
                $failed,
            ));

            return;
        }

        Log::info('Zenith bulk operation completed.', [
            'operation' => 'cancel-pending-jobs',
            'affected' => $result->totalCancelled,
            'scope' => $this->scope->value,
            'queue' => $this->queueName,
            'batched' => $batched,
            'failed' => $failed,
            'operation_id' => $result->operationId,
        ]);
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = ['zenith', 'bulk:cancel-pending-jobs', 'scope:'.$this->scope->value];

        if ($this->queueName !== null) {
            $tags[] = 'queue:'.$this->queueName;
        }

        if ($this->operationId !== null) {
            $tags[] = 'operation:'.$this->operationId;
        }

        return $tags;
    }
}
