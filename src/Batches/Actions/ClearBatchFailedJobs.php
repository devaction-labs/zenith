<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches\Actions;

use DevactionLabs\Zenith\FailedJobs\Actions\RemoveFailedJob;
use Illuminate\Bus\BatchRepository;

/**
 * Clears failed jobs already materialised on a single batch record — the
 * repository-native source of truth for that batch's failedJobIds.
 */
final readonly class ClearBatchFailedJobs
{
    private const int CHUNK_SIZE = 100;

    public function __construct(
        private BatchRepository $batches,
        private RemoveFailedJob $remove,
    ) {}

    public function handle(string $id): int
    {
        $batch = $this->batches->find($id);

        if ($batch === null) {
            return 0;
        }

        $failedJobIds = [];

        foreach ($batch->failedJobIds as $jobId) {
            if (! is_string($jobId) || trim($jobId) === '') {
                continue;
            }

            $failedJobIds[$jobId] = true;
        }

        $cleared = 0;

        foreach (array_chunk(array_keys($failedJobIds), self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $jobId) {
                $this->remove->handle($jobId);
                $cleared++;
            }
        }

        return $cleared;
    }
}
