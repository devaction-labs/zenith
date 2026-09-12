<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Monitoring\Actions;

use DevactionLabs\Zenith\BulkOperations\BulkOperationChunkResult;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\Zenith\FailedJobs\Actions\RetryFailedJob;
use DevactionLabs\Zenith\Monitoring\MonitoringTagGuard;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Throwable;

final readonly class RetryFailedJobs
{
    public function __construct(
        private TagRepository $tags,
        private JobRepository $jobs,
        private RetryFailedJob $retry,
        private MonitoringTagGuard $guard,
        private BulkOperationSnapshot $snapshots,
    ) {}

    public function processChunk(string $tag, ?string $operationId = null): BulkOperationChunkResult
    {
        $this->guard->ensureMonitored($this->tags, $tag);

        $operationId ??= $this->snapshots->createFromSortedSet("failed:{$tag}");

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
}
