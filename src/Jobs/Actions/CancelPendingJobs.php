<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Jobs\Actions;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\HorizonNewDawn\Jobs\Data\CancelPendingJobsChunkResultData;
use DevactionLabs\HorizonNewDawn\Jobs\PendingJobCancellationResult;
use DevactionLabs\HorizonNewDawn\Jobs\PendingJobCancellationScope;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

final readonly class CancelPendingJobs
{
    public function __construct(
        private JobRepository $jobs,
        private CancelPendingJob $cancel,
        private BulkOperationSnapshot $snapshots,
    ) {}

    public function processChunk(
        PendingJobCancellationScope $scope,
        ?string $queue = null,
        ?string $operationId = null,
    ): CancelPendingJobsChunkResultData {
        $operationId ??= $this->snapshots->createFromSortedSet('pending_jobs');

        $ids = $this->snapshots->nextChunk($operationId);

        if ($ids === []) {
            $total = $this->snapshots->finish($operationId);

            return new CancelPendingJobsChunkResultData(
                operationId: $operationId,
                complete: true,
                totalCancelled: $total,
                chunkCancelled: 0,
                chunkBatched: 0,
                chunkFailed: 0,
            );
        }

        $cancelled = 0;
        $batched = 0;
        $failed = 0;
        $hydrated = [];

        foreach ($this->jobs->getJobs($ids) as $job) {
            if (! is_object($job) || ! is_string($job->id ?? null) || $job->id === '') {
                continue;
            }

            $hydrated[$job->id] = $job;
        }

        try {
            foreach ($ids as $id) {
                $job = $hydrated[$id] ?? null;

                if ($job === null || ($queue !== null && ($job->queue ?? null) !== $queue)) {
                    $this->snapshots->acknowledge($operationId, $id);

                    continue;
                }

                try {
                    match ($this->cancel->handle($id, $scope)) {
                        PendingJobCancellationResult::Cancelled => $cancelled++,
                        PendingJobCancellationResult::Batched => $batched++,
                        PendingJobCancellationResult::NotCancellable => null,
                    };
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                }

                $this->snapshots->acknowledge($operationId, $id);
            }
        } catch (Throwable $exception) {
            $this->snapshots->renew($operationId);

            throw $exception;
        }

        $totalCancelled = $this->snapshots->addAffected($operationId, $cancelled);
        $complete = ! $this->snapshots->hasMore($operationId);

        if ($complete) {
            $this->snapshots->cleanup($operationId);
        }

        return new CancelPendingJobsChunkResultData(
            operationId: $operationId,
            complete: $complete,
            totalCancelled: $totalCancelled,
            chunkCancelled: $cancelled,
            chunkBatched: $batched,
            chunkFailed: $failed,
        );
    }
}
