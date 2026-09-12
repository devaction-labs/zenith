<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use Illuminate\Contracts\Cache\Repository;

/**
 * Reads whether `schedule:run` currently skips every event, mirroring the
 * cache flag `schedule:pause` and `schedule:resume` themselves maintain.
 */
final readonly class SchedulePauseStatus
{
    private const string CACHE_KEY = 'illuminate:schedule:paused';

    public function __construct(
        private Repository $cache,
    ) {}

    public function paused(): bool
    {
        return (bool) $this->cache->get(self::CACHE_KEY, false);
    }
}
