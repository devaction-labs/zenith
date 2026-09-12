<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

use Closure;

/**
 * Cap how many jobs sharing a name run at once across every worker.
 *
 * The job holds a QueueBudget slot while it runs and releases it in a
 * `finally` block, so the slot frees up even when the job throws. A slot also
 * expires after `$expiresAfter` seconds in case the worker dies, so keep it
 * above the job timeout. While every slot is taken the job is released back to
 * its queue; each release counts as an attempt unless the job defines
 * `retryUntil()`, and `dontRelease()` deletes it instead.
 */
final class EnforceQueueConcurrency
{
    use ReleasesThrottledJobs;

    private const int RELEASE_DELAY = 10;

    public function __construct(
        private readonly QueueBudget $budget,
        private readonly string $name,
        private readonly int $limit,
        private readonly int $expiresAfter = 60,
    ) {}

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (! $this->budget->acquireSlot($this->name, $this->limit, $this->expiresAfter)) {
            return $this->throttle($job, self::RELEASE_DELAY);
        }

        try {
            return $next($job);
        } finally {
            $this->budget->releaseSlot($this->name);
        }
    }
}
