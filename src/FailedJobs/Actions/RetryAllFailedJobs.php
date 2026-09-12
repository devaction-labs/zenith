<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\FailedJobs\Actions;

use DevactionLabs\Zenith\BulkOperations\BulkOperationChunkResult;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

final readonly class RetryAllFailedJobs
{
    public function __construct(
        private JobRepository $jobs,
        private RetryFailedJob $retry,
        private BulkOperationSnapshot $snapshots,
    ) {}

    public function processChunk(
        ?string $operationId = null,
        ?string $connection = null,
        ?string $queue = null,
    ): BulkOperationChunkResult {
        $operationId ??= $this->snapshots->createFromSortedSet('failed_jobs');

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

                if (
                    $job === null
                    || ($connection !== null && ($job->connection ?? null) !== $connection)
                    || ($queue !== null && ($job->queue ?? null) !== $queue)
                ) {
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
}
