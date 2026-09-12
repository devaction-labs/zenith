<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chains;

use Closure;
use DevactionLabs\Zenith\Limits\ReleasesThrottledJobs;
use Illuminate\Contracts\Queue\Job;

/**
 * Enforce the ChainBy chain a queued job belongs to.
 *
 * Add it to the middleware() of every job carrying a #[ChainBy] attribute or
 * a fluent chainKey() method; a job dispatched without this middleware runs
 * immediately regardless of its position, which can stall the rest of its
 * chain since ChainSequencer::advance() is only ever called for tickets that
 * were actually gated here or reported as terminally failed.
 *
 * A job that is not yet next is released back to its queue, the same way
 * EnforceQueueBudget and EnforceQueueConcurrency throttle jobs; each release
 * counts as an attempt unless the job defines retryUntil(). A job that runs
 * and succeeds advances the chain immediately. A job that throws leaves the
 * chain exactly where it was: the queue's own retry policy decides whether
 * the same ticket runs again, and ChainFailureListener advances the chain
 * once Laravel reports the job as permanently failed.
 */
final class EnforceChainOrder
{
    use ReleasesThrottledJobs;

    private const int RETRY_DELAY = 1;

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $chain = $this->chainFor($job);

        if ($chain === null) {
            return $next($job);
        }

        if (! ChainSequencer::isNext($chain->key, $chain->ticket)) {
            return $this->throttle($job, self::RETRY_DELAY);
        }

        $result = $next($job);

        ChainSequencer::advance($chain->key, $chain->ticket);

        return $result;
    }

    private function chainFor(object $job): ?ChainPayload
    {
        $queueJob = property_exists($job, 'job') ? $job->job : null;

        return $queueJob instanceof Job ? ChainPayload::from($queueJob->payload()) : null;
    }
}
