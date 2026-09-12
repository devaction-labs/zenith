<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

/**
 * Tracks recent Illuminate\Queue\Events\QueueFailedOver occurrences in a
 * short, cache-backed window.
 *
 * Entries live in the store named by `zenith.queue_failover.store`, or the
 * default store when it is null, and are pruned to the last
 * `zenith.queue_failover.window_minutes` minutes on every read. The bounded
 * list itself is capped so a failover storm cannot grow the cache entry
 * without limit.
 */
final readonly class QueueFailoverActivity
{
    private const string CACHE_KEY = 'zenith:queue-failovers';

    private const int MAX_RECORDED = 100;

    public function __construct(
        private CacheFactory $cache,
    ) {}

    public function record(?string $connection): void
    {
        $store = $this->store();
        $entries = $this->entries($store);
        $entries[] = [
            'connection' => $connection,
            'occurredAt' => Date::now()->getTimestamp(),
        ];

        $store->put(
            self::CACHE_KEY,
            array_slice($entries, -self::MAX_RECORDED),
            $this->windowSeconds(),
        );
    }

    /**
     * @return list<array{connection: ?string, occurredAt: int}>
     */
    public function recent(): array
    {
        $cutoff = Date::now()->getTimestamp() - $this->windowSeconds();

        return array_values(array_filter(
            $this->entries($this->store()),
            static fn (array $entry): bool => $entry['occurredAt'] >= $cutoff,
        ));
    }

    public function windowMinutes(): int
    {
        return max(1, intdiv($this->windowSeconds(), 60));
    }

    private function windowSeconds(): int
    {
        return max(60, Config::integer('zenith.queue_failover.window_minutes', 60) * 60);
    }

    /**
     * @return list<array{connection: ?string, occurredAt: int}>
     */
    private function entries(Repository $store): array
    {
        $value = $store->get(self::CACHE_KEY);

        if (! is_array($value)) {
            return [];
        }

        $entries = [];

        foreach ($value as $entry) {
            if (! is_array($entry) || ! is_numeric($entry['occurredAt'] ?? null)) {
                continue;
            }

            $entries[] = [
                'connection' => is_string($entry['connection'] ?? null) ? $entry['connection'] : null,
                'occurredAt' => (int) $entry['occurredAt'],
            ];
        }

        return $entries;
    }

    private function store(): Repository
    {
        $store = config('zenith.queue_failover.store');

        return $this->cache->store(is_string($store) ? $store : null);
    }
}
