<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Signals;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Date;

/**
 * A wait that releases the running queue job instead of blocking a worker.
 *
 * The deadline is recorded by the first wait and survives every redelivery, so a
 * waiting job times out once the requested number of seconds has passed, however
 * many times it was released in between.
 *
 * @internal
 */
final readonly class QueuedWait
{
    private const int DEADLINE_GRACE_SECONDS = 300;

    private function __construct(
        private Job $job,
        private int $retryAfter,
    ) {}

    /**
     * Resolve the running queue job when it can be released for the given delay.
     */
    public static function current(int $retryAfter): ?self
    {
        if ($retryAfter < 1 || ! app()->bound(Job::class)) {
            return null;
        }

        return new self(app(Job::class), $retryAfter);
    }

    /**
     * Determine whether the deadline stored under the key, recorded on first use, has passed.
     */
    public function hasTimedOut(Repository $cache, string $key, int $seconds): bool
    {
        $now = Date::now()->getTimestamp();

        $cache->add($key, $now + $seconds, $seconds + $this->retryAfter + self::DEADLINE_GRACE_SECONDS);

        $deadline = filter_var($cache->get($key), FILTER_VALIDATE_INT);

        return $deadline === false || $now >= $deadline;
    }

    public function release(): void
    {
        $this->job->release($this->retryAfter);
    }
}
