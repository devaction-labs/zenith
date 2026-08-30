<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches\Actions;

use DevactionLabs\HorizonNewDawn\Batches\BatchesData;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchQuery;
use DevactionLabs\HorizonNewDawn\Batches\RetainedBatchScanner;
use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationChunkResult;
use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\HorizonNewDawn\FailedJobs\Actions\RetryFailedJob;
use Illuminate\Bus\BatchRepository;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

final readonly class RetryQueueBatches
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private BatchRepository $batches,
        private BatchesData $data,
        private JobRepository $jobs,
        private RetryFailedJob $retry,
        private BulkOperationSnapshot $snapshots,
        private ?DatabaseBatchQuery $databaseQuery = null,
    ) {}

    public function processChunk(string $queue, ?string $operationId = null): BulkOperationChunkResult
    {
        $operationId ??= $this->createSnapshot($queue);

        $ids = $this->snapshots->nextChunk($operationId);

        if ($ids === []) {
            return BulkOperationChunkResult::completed(
                $operationId,
                $this->snapshots->finish($operationId),
            );
        }

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

                if ($job === null) {
                    $this->snapshots->acknowledge($operationId, $id);

                    continue;
                }

                if ($this->retry->handleBulk($id, $job)) {
                    $this->snapshots->addAffected($operationId, 1);
                }

                $this->snapshots->acknowledge($operationId, $id);
            }
        } catch (Throwable $exception) {
            $this->snapshots->renew($operationId);

            throw $exception;
        }

        $totalAffected = $this->snapshots->totalAffected($operationId);

        if ($this->snapshots->hasMore($operationId)) {
            return BulkOperationChunkResult::continuing($operationId, $totalAffected);
        }

        return BulkOperationChunkResult::completed(
            $operationId,
            $this->snapshots->finish($operationId),
        );
    }

    private function createSnapshot(string $queue): string
    {
        if ($this->databaseQuery instanceof DatabaseBatchQuery) {
            return $this->snapshots->createFromIds(
                $this->databaseQuery->failedJobIdsForQueue($queue),
            );
        }

        return $this->snapshots->createFromIds(
            $this->failedJobIdsFromRepository($queue),
        );
    }

    /** @return \Generator<int, string> */
    private function failedJobIdsFromRepository(string $queue): \Generator
    {
        foreach ((new RetainedBatchScanner($this->batches))->pages(self::PAGE_SIZE) as $page) {
            foreach ($page as $batch) {
                if ($batch->failedJobs === 0 || $this->data->queue($batch) !== $queue) {
                    continue;
                }

                foreach ($batch->failedJobIds as $jobId) {
                    if (! is_string($jobId) || trim($jobId) === '') {
                        continue;
                    }

                    yield $jobId;
                }
            }
        }
    }
}
