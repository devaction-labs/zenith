<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\FailedJobs\Actions;

use DevactionLabs\Zenith\FailedJobs\FailedJobRetryEligibility;
use DevactionLabs\Zenith\FailedJobs\FailedJobRetryLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Jobs\RetryFailedJob as HorizonRetryFailedJob;

final readonly class RetryFailedJob
{
    public function __construct(
        private Dispatcher $bus,
        private JobRepository $jobs,
        private FailedJobRetryEligibility $eligibility,
        private ?FailedJobRetryLock $lock = null,
    ) {}

    public function handle(string $id, ?object $job = null): bool
    {
        return $this->handleWithPolicy($id, $job, bulk: false);
    }

    public function handleBulk(string $id, ?object $job = null): bool
    {
        return $this->handleWithPolicy($id, $job, bulk: true);
    }

    private function handleWithPolicy(string $id, ?object $job, bool $bulk): bool
    {
        if ($this->lock !== null) {
            return $this->lock->run(
                $id,
                fn (): bool => $this->retry($id, null, $bulk),
            );
        }

        return $this->retry($id, $job, $bulk);
    }

    private function retry(string $id, ?object $job, bool $bulk): bool
    {
        $job ??= $this->jobs->findFailed($id);

        if (! is_object($job) || ($job->id ?? null) !== $id) {
            return false;
        }

        $allowed = $bulk
            ? $this->eligibility->allowsBulk($job)
            : $this->eligibility->allows($job);

        if (! $allowed) {
            return false;
        }

        $this->bus->dispatch(new HorizonRetryFailedJob($id));

        return true;
    }
}
