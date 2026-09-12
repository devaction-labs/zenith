<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chunks;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use LogicException;

/**
 * Buffers items and runs a job with them in chunks, once a buffer holds $size items
 * or its timeout has elapsed.
 *
 * Every read-modify-write of a buffer happens under an atomic cache lock, so
 * concurrent producers never lose items on any cache store that supports locks.
 * Buffers live in the store named by `zenith.chunks.store`, or the default store
 * when it is null, for at least `zenith.chunks.ttl` seconds after their last push.
 * The scheduled `zenith:chunk-flush` event calls flushDue() to flush buffers whose
 * timeout has elapsed.
 *
 * @phpstan-type Chunk array{job: class-string, items: list<array<string, mixed>>, due_at: int|null}
 */
final readonly class ChunkBuffer
{
    private const string BUFFER_PREFIX = 'zenith:chunk:';

    private const string BUFFER_LOCK_PREFIX = 'zenith:chunk-lock:';

    private const string SCHEDULE_KEY = 'zenith:chunk-schedule';

    private const string SCHEDULE_LOCK = 'zenith:chunk-schedule-lock';

    private const int LOCK_SECONDS = 10;

    private const int EXPIRY_GRACE_SECONDS = 300;

    public function __construct(
        private Factory $cache,
        private Dispatcher $bus,
    ) {}

    /**
     * Add an item to the named buffer and dispatch the chunk once it is full or due.
     *
     * The push that opens a buffer fixes its job class and timeout; the timeout counts
     * from that first item.
     *
     * @param  class-string  $job
     * @param  array<string, mixed>  $item
     */
    public function push(string $name, string $job, array $item, int $size = 100, ?int $timeout = null): void
    {
        $chunk = $this->exclusively(
            self::BUFFER_LOCK_PREFIX.$name,
            fn (): ?array => $this->append($name, $job, $item, $size, $timeout),
        );

        if ($chunk !== null) {
            $this->dispatch($chunk);
        }
    }

    /**
     * Dispatch every buffer whose timeout has elapsed.
     *
     * @return int The number of chunks dispatched.
     */
    public function flushDue(): int
    {
        $now = Date::now()->getTimestamp();
        $dispatched = 0;

        foreach ($this->schedule() as $key => $dueAt) {
            if ($dueAt > $now) {
                continue;
            }

            $name = Str::after($key, self::BUFFER_PREFIX);
            $chunk = $this->exclusively(
                self::BUFFER_LOCK_PREFIX.$name,
                fn (): ?array => $this->takeDue($name),
            );

            if ($chunk !== null) {
                $this->dispatch($chunk);
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * @param  class-string  $job
     * @param  array<string, mixed>  $item
     * @return Chunk|null
     */
    private function append(string $name, string $job, array $item, int $size, ?int $timeout): ?array
    {
        $chunk = $this->buffer($name) ?? $this->open($name, $job, $timeout);
        $chunk['items'][] = $item;

        if (count($chunk['items']) >= max(1, $size) || $this->isDue($chunk)) {
            $this->discard($name, $chunk);

            return $chunk;
        }

        $this->store()->put(self::BUFFER_PREFIX.$name, $chunk, $this->ttl($chunk));

        return null;
    }

    /**
     * @return Chunk|null
     */
    private function takeDue(string $name): ?array
    {
        $chunk = $this->buffer($name);

        if ($chunk === null) {
            $this->unschedule($name);

            return null;
        }

        if (! $this->isDue($chunk)) {
            return null;
        }

        $this->discard($name, $chunk);

        return $chunk;
    }

    /**
     * @param  class-string  $job
     * @return Chunk
     */
    private function open(string $name, string $job, ?int $timeout): array
    {
        $dueAt = $timeout === null ? null : Date::now()->getTimestamp() + $timeout;

        if ($dueAt !== null) {
            $this->reschedule($name, $dueAt);
        }

        return ['job' => $job, 'items' => [], 'due_at' => $dueAt];
    }

    /**
     * @param  Chunk  $chunk
     */
    private function discard(string $name, array $chunk): void
    {
        $this->store()->forget(self::BUFFER_PREFIX.$name);

        if ($chunk['due_at'] !== null) {
            $this->unschedule($name);
        }
    }

    /**
     * @param  Chunk  $chunk
     */
    private function dispatch(array $chunk): void
    {
        $this->bus->dispatch(new RunChunkJob($chunk['job'], $chunk['items']));
    }

    /**
     * @param  Chunk  $chunk
     */
    private function isDue(array $chunk): bool
    {
        return $chunk['due_at'] !== null && $chunk['due_at'] <= Date::now()->getTimestamp();
    }

    /**
     * @param  Chunk  $chunk
     */
    private function ttl(array $chunk): int
    {
        $ttl = Config::integer('zenith.chunks.ttl', 86400);

        if ($chunk['due_at'] === null) {
            return $ttl;
        }

        return max($ttl, $chunk['due_at'] - Date::now()->getTimestamp() + self::EXPIRY_GRACE_SECONDS);
    }

    /**
     * @return Chunk|null
     */
    private function buffer(string $name): ?array
    {
        $chunk = $this->store()->get(self::BUFFER_PREFIX.$name);

        if (! is_array($chunk)) {
            return null;
        }

        $job = $chunk['job'] ?? null;
        $items = $chunk['items'] ?? null;
        $dueAt = $chunk['due_at'] ?? null;

        if (! is_string($job) || ! class_exists($job)) {
            throw new LogicException("Chunk buffer [{$name}] does not name an existing job class.");
        }

        return [
            'job' => $job,
            'items' => is_array($items) ? array_values(array_map(self::item(...), $items)) : [],
            'due_at' => is_int($dueAt) ? $dueAt : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(mixed $item): array
    {
        return is_array($item) ? array_filter($item, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    private function reschedule(string $name, int $dueAt): void
    {
        $this->exclusively(self::SCHEDULE_LOCK, function () use ($name, $dueAt): void {
            $schedule = $this->schedule();
            $schedule[self::BUFFER_PREFIX.$name] = $dueAt;

            $this->store()->forever(self::SCHEDULE_KEY, $schedule);
        });
    }

    private function unschedule(string $name): void
    {
        $this->exclusively(self::SCHEDULE_LOCK, function () use ($name): void {
            $schedule = $this->schedule();
            unset($schedule[self::BUFFER_PREFIX.$name]);

            $this->store()->forever(self::SCHEDULE_KEY, $schedule);
        });
    }

    /**
     * The due time of every buffer with a timeout, keyed by buffer key.
     *
     * @return array<string, int>
     */
    private function schedule(): array
    {
        $schedule = $this->store()->get(self::SCHEDULE_KEY);

        if (! is_array($schedule)) {
            return [];
        }

        return array_filter(array_filter($schedule, is_int(...)), is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function exclusively(string $lock, Closure $callback): mixed
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Zenith chunk buffers require a cache store that supports atomic locks.');
        }

        $mutex = $store->lock($lock, self::LOCK_SECONDS);
        $mutex->block(self::LOCK_SECONDS);

        try {
            return $callback();
        } finally {
            $mutex->release();
        }
    }

    private function store(): Repository
    {
        $store = config('zenith.chunks.store');

        return $this->cache->store(is_string($store) ? $store : null);
    }
}
