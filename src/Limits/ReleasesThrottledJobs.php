<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

/**
 * Release a throttled job back to its queue, or drop it.
 *
 * A release counts as an attempt: a throttled job fails once it reaches its
 * `$tries`, unless it defines `retryUntil()`, which makes the worker check the
 * deadline instead of the attempt count.
 */
trait ReleasesThrottledJobs
{
    private ?int $releaseAfter = null;

    private bool $shouldRelease = true;

    /**
     * Release throttled jobs after the given number of seconds instead of the default delay.
     */
    public function releaseAfter(int $seconds): static
    {
        $this->releaseAfter = $seconds;

        return $this;
    }

    /**
     * Delete throttled jobs without running them instead of releasing them.
     */
    public function dontRelease(): static
    {
        $this->shouldRelease = false;

        return $this;
    }

    private function throttle(object $job, int $defaultDelay): null
    {
        if ($this->shouldRelease && method_exists($job, 'release')) {
            $job->release($this->releaseAfter ?? $defaultDelay);
        }

        return null;
    }
}
