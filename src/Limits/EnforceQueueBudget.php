<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

use Closure;

/**
 * Enforce a global rate budget on a queued job.
 *
 * While the budget is exhausted the job is released back to its queue until
 * the window resets, or after `releaseAfter()` seconds. Each release counts as
 * an attempt, so a throttled job fails once it reaches `$tries` unless it
 * defines `retryUntil()`; call `dontRelease()` to delete it instead.
 */
final class EnforceQueueBudget
{
    use ReleasesThrottledJobs;

    public function __construct(
        private readonly QueueBudget $budget,
        private readonly string $name,
        private readonly int $allowed,
        private readonly int $period,
        private readonly int $weight = 1,
        private readonly ?string $partition = null,
    ) {}

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        if ($this->budget->consume($this->name, $this->allowed, $this->period, $this->weight, $this->partition)) {
            return $next($job);
        }

        return $this->throttle($job, max(1, $this->budget->availableIn($this->name, $this->partition)));
    }
}
