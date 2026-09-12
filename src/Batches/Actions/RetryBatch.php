<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches\Actions;

use DevactionLabs\Zenith\FailedJobs\Actions\RetryFailedJob;
use Illuminate\Bus\BatchRepository;
use Laravel\Horizon\Contracts\JobRepository;

/**
 * Retries failed jobs already materialised on a single batch record — the
 * repository-native source of truth for that batch's failedJobIds.
 */
final readonly class RetryBatch
{
    private const int CHUNK_SIZE = 100;

    public function __construct(
        private BatchRepository $batches,
        private JobRepository $jobs,
        private RetryFailedJob $retry,
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

        if ($failedJobIds === []) {
            return 0;
        }

        $scheduled = 0;
        $seen = [];

        foreach (array_chunk(array_keys($failedJobIds), self::CHUNK_SIZE) as $chunk) {
            foreach ($this->jobs->getJobs($chunk) as $job) {
                if (! is_object($job) || ! is_string($job->id ?? null) || $job->id === '') {
                    continue;
                }

                if (! isset($failedJobIds[$job->id]) || isset($seen[$job->id])) {
                    continue;
                }

                $seen[$job->id] = true;

                if ($this->retry->handleBulk($job->id, $job)) {
                    $scheduled++;
                }
            }
        }

        return $scheduled;
    }
}
