<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\FailedJobs\Actions;

use DevactionLabs\Zenith\BulkOperations\BulkOperationChunkResult;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

final readonly class ClearFailedJobs
{
    public function __construct(
        private JobRepository $jobs,
        private FailedJobProviderInterface $failedJobs,
        private BulkOperationSnapshot $snapshots,
    ) {}

    public function processChunk(?string $operationId = null): BulkOperationChunkResult
    {
        $operationId ??= $this->snapshots->createFromSortedSet('failed_jobs');

        $ids = $this->snapshots->nextChunk($operationId);

        if ($ids === []) {
            return BulkOperationChunkResult::completed(
                $operationId,
                $this->snapshots->finish($operationId),
            );
        }

        try {
            foreach ($ids as $id) {
                $this->jobs->deleteFailed($id);
                $this->failedJobs->forget($id);
                $this->snapshots->addAffected($operationId, 1);
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
