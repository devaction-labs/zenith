<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Tests\Support;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

function bulkSnapshotRedis(): BulkOperationSnapshotRedisConnection
{
    $connection = new BulkOperationSnapshotRedisConnection;
    $redis = mockDashboardContract(RedisFactory::class);
    dashboardReturns($redis, 'connection', $connection);
    app()->instance(RedisFactory::class, $redis);

    return $connection;
}

function bulkSnapshot(): BulkOperationSnapshot
{
    return app(BulkOperationSnapshot::class);
}

/**
 * Minimal Horizon Redis stand-in for bulk-operation snapshot tests.
 */
final class BulkOperationSnapshotRedisConnection extends Connection
{
    /** @var array<string, array<string, float>> */
    public array $sortedSets = [];

    /** @var array<string, array<string, true>> */
    public array $sets = [];

    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public int $failZremRemaining = 0;

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, $callback, $method = 'subscribe'): void {}

    /** @param array<string, float|int> $members */
    public function seedSortedSet(string $key, array $members): void
    {
        $this->sortedSets[$key] = [];

        foreach ($members as $member => $score) {
            $this->sortedSets[$key][(string) $member] = (float) $score;
        }
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function command($method, array $parameters = []): mixed
    {
        $method = strtolower((string) $method);

        return match ($method) {
            'zrangestore' => $this->zrangestore(
                (string) $parameters[0],
                (string) $parameters[1],
                (int) $parameters[2],
                (int) $parameters[3],
            ),
            'zrem' => $this->zrem((string) $parameters[0], ...array_slice($parameters, 1)),
            default => $this->{$method}(...$parameters),
        };
    }

    public function zrangestore(string $destination, string $source, int $start, int $stop): int
    {
        $members = $this->zrange($source, $start, $stop, ['withscores' => true]);
        $this->sortedSets[$destination] = [];

        foreach ($members as $member => $score) {
            $this->sortedSets[$destination][(string) $member] = (float) $score;
        }

        return count($this->sortedSets[$destination]);
    }

    /** @return array<int|string, float|string> */
    public function zrange(string $key, int $start, int $stop, mixed $options = null): array
    {
        $members = $this->sortedSets[$key] ?? [];
        asort($members, SORT_NUMERIC);
        $ordered = array_keys($members);

        if ($ordered === []) {
            return [];
        }

        $count = count($ordered);
        $start = $start < 0 ? $count + $start : $start;
        $stop = $stop < 0 ? $count + $stop : $stop;
        $start = max(0, $start);
        $stop = min($count - 1, $stop);

        if ($start > $stop) {
            return [];
        }

        $slice = array_slice($ordered, $start, $stop - $start + 1);
        $withScores = is_array($options) && (
            ($options['withscores'] ?? false) === true
            || in_array('withscores', $options, true)
        );

        if (! $withScores) {
            return $slice;
        }

        $scored = [];

        foreach ($slice as $member) {
            $scored[$member] = $members[$member];
        }

        return $scored;
    }

    public function zadd(string $key, float|int $score, string $member): int
    {
        $created = ! isset($this->sortedSets[$key][$member]);
        $this->sortedSets[$key][$member] = (float) $score;

        return $created ? 1 : 0;
    }

    public function zrem(string $key, mixed ...$members): int
    {
        if ($this->failZremRemaining > 0) {
            $this->failZremRemaining--;

            throw new \RuntimeException('Snapshot acknowledge ZREM failed.');
        }

        $removed = 0;

        foreach ($members as $member) {
            if (! is_string($member) && ! is_int($member)) {
                continue;
            }

            $member = (string) $member;

            if (isset($this->sortedSets[$key][$member])) {
                unset($this->sortedSets[$key][$member]);
                $removed++;
            }
        }

        return $removed;
    }

    public function zcard(string $key): int
    {
        return count($this->sortedSets[$key] ?? []);
    }

    public function sadd(string $key, string $member): int
    {
        if (isset($this->sets[$key][$member])) {
            return 0;
        }

        $this->sets[$key][$member] = true;

        return 1;
    }

    /** @param array<string, string> $values */
    public function hmset(string $key, array $values): bool
    {
        foreach ($values as $field => $value) {
            $this->hashes[$key][(string) $field] = (string) $value;
        }

        return true;
    }

    public function hset(string $key, string $field, string $value): int
    {
        $created = ! isset($this->hashes[$key][$field]);
        $this->hashes[$key][$field] = $value;

        return $created ? 1 : 0;
    }

    public function hget(string $key, string $field): string|false
    {
        return $this->hashes[$key][$field] ?? false;
    }

    public function hincrby(string $key, string $field, int $increment): int
    {
        $current = (int) ($this->hashes[$key][$field] ?? 0);
        $next = $current + $increment;
        $this->hashes[$key][$field] = (string) $next;

        return $next;
    }

    public function expire(string $key, int $seconds): bool
    {
        $this->ttls[$key] = $seconds;

        return true;
    }

    public function ttl(string $key): int
    {
        return $this->ttls[$key] ?? -2;
    }

    public function exists(string $key): int
    {
        return isset($this->sortedSets[$key])
            || isset($this->sets[$key])
            || isset($this->hashes[$key])
            ? 1
            : 0;
    }

    public function del(string ...$keys): int
    {
        $removed = 0;

        foreach ($keys as $key) {
            $existed = $this->exists($key) === 1;
            unset(
                $this->sortedSets[$key],
                $this->sets[$key],
                $this->hashes[$key],
                $this->ttls[$key],
            );

            if ($existed) {
                $removed++;
            }
        }

        return $removed;
    }
}
