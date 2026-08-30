<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Queues;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

final readonly class QueuePauseMetadata
{
    public function __construct(private CacheFactory $cache) {}

    public function storeUntil(string $connection, string $queue, CarbonImmutable $until): void
    {
        $this->cache->store()->put(
            $this->key($connection, $queue),
            $until->timestamp,
            $until,
        );
    }

    public function pausedUntil(string $connection, string $queue): ?int
    {
        $value = $this->cache->store()->get($this->key($connection, $queue));

        return is_numeric($value) ? (int) $value : null;
    }

    public function forget(string $connection, string $queue): void
    {
        $this->cache->store()->forget($this->key($connection, $queue));
    }

    /**
     * Laravel 13.25+ QueueManager::pauseAll() persists this cache flag.
     * Reading it keeps the UI aligned with `queue:pause --all`.
     */
    public function laravelGlobalPauseActive(): bool
    {
        return (bool) $this->cache->store()->get('illuminate:queues:paused');
    }

    private function key(string $connection, string $queue): string
    {
        return 'horizon-new-dawn:queue-pause:'.hash('sha256', $connection."\0".$queue);
    }
}
