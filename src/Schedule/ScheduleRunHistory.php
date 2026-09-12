<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use Closure;
use DevactionLabs\Zenith\Schedule\Data\ScheduleRunData;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * The last N runs of each scheduled event, kept compactly per event id.
 *
 * History lives in the store named by `zenith.schedule_history.store`, or the
 * default store when it is null, for `zenith.schedule_history.ttl` seconds
 * after its most recent run, bounded to `zenith.schedule_history.limit`
 * entries (most recent first). This survives worker and scheduler restarts
 * as long as the underlying store does, such as Redis, without needing a
 * package migration.
 *
 * @phpstan-type RunEntry array{status: string, startedAt: float, durationMs: float|null, exitCode: int|null, outputTail: string|null}
 */
final readonly class ScheduleRunHistory
{
    private const string KEY_PREFIX = 'zenith:schedule-history:';

    private const string LOCK_PREFIX = 'zenith:schedule-history-lock:';

    private const int LOCK_SECONDS = 10;

    public function __construct(
        private Factory $cache,
    ) {}

    public function record(string $eventId, ScheduleRunData $run): void
    {
        $this->exclusively($eventId, function () use ($eventId, $run): void {
            $limit = max(1, Config::integer('zenith.schedule_history.limit', 10));
            $runs = $this->entries($eventId);
            array_unshift($runs, $this->entry($run));
            $runs = array_slice($runs, 0, $limit);

            $this->store()->put(
                self::KEY_PREFIX.$eventId,
                $runs,
                Config::integer('zenith.schedule_history.ttl', 604800),
            );
        });
    }

    /**
     * @return list<ScheduleRunData>
     */
    public function for(string $eventId): array
    {
        return array_map(
            /** @param array<mixed, mixed> $entry */
            static fn (array $entry): ScheduleRunData => new ScheduleRunData(
                status: is_string($entry['status'] ?? null) ? $entry['status'] : 'success',
                startedAt: is_numeric($entry['startedAt'] ?? null) ? (float) $entry['startedAt'] : 0.0,
                durationMs: is_numeric($entry['durationMs'] ?? null) ? (float) $entry['durationMs'] : null,
                exitCode: is_int($entry['exitCode'] ?? null) ? $entry['exitCode'] : null,
                outputTail: is_string($entry['outputTail'] ?? null) ? $entry['outputTail'] : null,
            ),
            $this->entries($eventId),
        );
    }

    /**
     * @return RunEntry
     */
    private function entry(ScheduleRunData $run): array
    {
        return [
            'status' => $run->status,
            'startedAt' => $run->startedAt,
            'durationMs' => $run->durationMs,
            'exitCode' => $run->exitCode,
            'outputTail' => $run->outputTail,
        ];
    }

    /**
     * @return list<array<mixed, mixed>>
     */
    private function entries(string $eventId): array
    {
        $stored = $this->store()->get(self::KEY_PREFIX.$eventId);

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, static fn (mixed $entry): bool => is_array($entry)));
    }

    /**
     * @param  Closure(): void  $callback
     */
    private function exclusively(string $eventId, Closure $callback): void
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Zenith schedule history requires a cache store that supports atomic locks.');
        }

        $mutex = $store->lock(self::LOCK_PREFIX.$eventId, self::LOCK_SECONDS);
        $mutex->block(self::LOCK_SECONDS);

        try {
            $callback();
        } finally {
            $mutex->release();
        }
    }

    private function store(): Repository
    {
        $store = config('zenith.schedule_history.store');

        return $this->cache->store(is_string($store) ? $store : null);
    }
}
