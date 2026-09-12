<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Tests\Support;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Mockery\MockInterface;

/**
 * @return array{redis: RedisFactory&MockInterface, connection: TelemetryRedisConnection}
 */
function telemetryRedis(): array
{
    $connection = new TelemetryRedisConnection;
    $redis = mockDashboardContract(RedisFactory::class);
    dashboardReturns($redis, 'connection', $connection);

    return ['redis' => $redis, 'connection' => $connection];
}

/**
 * Minimal, stateful Horizon Redis stand-in for telemetry tests.
 *
 * Mirrors the subset of Redis commands the telemetry recorder, in-flight
 * tracker, and attempt history use. Every command call is also tallied in
 * {@see self::$callCounts} so tests can assert the recorder issues a fixed,
 * bounded number of round-trips per event instead of a wall-clock benchmark.
 */
final class TelemetryRedisConnection extends Connection
{
    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    /** @var array<string, array<string, float>> */
    public array $sortedSets = [];

    /** @var array<string, list<string>> */
    public array $lists = [];

    /** @var array<string, int> */
    public array $ttls = [];

    /** @var array<string, int> */
    public array $callCounts = [];

    /** @var list<array{0: string, 1: array<int|string, mixed>}> */
    public array $calls = [];

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, $callback, $method = 'subscribe'): void {}

    /** @param array<int, mixed> $parameters */
    public function command($method, array $parameters = []): mixed
    {
        return $this->{strtolower((string) $method)}(...$parameters);
    }

    public function hincrby(string $key, string $field, int $increment): int
    {
        $this->tally('hincrby', [$key, $field, $increment]);
        $current = (int) ($this->hashes[$key][$field] ?? 0);
        $next = $current + $increment;
        $this->hashes[$key][$field] = (string) $next;

        return $next;
    }

    /** @return array<string, string> */
    public function hgetall(string $key): array
    {
        $this->tally('hgetall', [$key]);

        return $this->hashes[$key] ?? [];
    }

    public function hget(string $key, string $field): string|false
    {
        $this->tally('hget', [$key, $field]);

        return $this->hashes[$key][$field] ?? false;
    }

    public function hset(string $key, string $field, string $value): int
    {
        $this->tally('hset', [$key, $field, $value]);
        $created = ! isset($this->hashes[$key][$field]);
        $this->hashes[$key][$field] = $value;

        return $created ? 1 : 0;
    }

    public function hdel(string $key, string ...$fields): int
    {
        $this->tally('hdel', [$key, ...$fields]);
        $removed = 0;

        foreach ($fields as $field) {
            if (isset($this->hashes[$key][$field])) {
                unset($this->hashes[$key][$field]);
                $removed++;
            }
        }

        return $removed;
    }

    public function zadd(string $key, float|int $score, string $member): int
    {
        $this->tally('zadd', [$key, $score, $member]);
        $created = ! isset($this->sortedSets[$key][$member]);
        $this->sortedSets[$key][$member] = (float) $score;

        return $created ? 1 : 0;
    }

    /** @return list<string>|array<string, float> */
    public function zrange(string $key, int $start, int $stop, mixed $options = null): array
    {
        $this->tally('zrange', [$key, $start, $stop]);
        $members = $this->sortedSets[$key] ?? [];
        asort($members, SORT_NUMERIC);
        $ordered = array_keys($members);
        $count = count($ordered);

        if ($count === 0) {
            return [];
        }

        $start = $start < 0 ? max(0, $count + $start) : $start;
        $stop = $stop < 0 ? $count + $stop : min($count - 1, $stop);

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

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        $this->tally('zremrangebyscore', [$key, $min, $max]);
        $lower = $this->scoreBoundary($min, -INF);
        $upper = $this->scoreBoundary($max, INF);
        $removed = 0;

        foreach ($this->sortedSets[$key] ?? [] as $member => $score) {
            if ($score >= $lower && $score <= $upper) {
                unset($this->sortedSets[$key][$member]);
                $removed++;
            }
        }

        return $removed;
    }

    public function zrem(string $key, string ...$members): int
    {
        $this->tally('zrem', [$key, ...$members]);
        $removed = 0;

        foreach ($members as $member) {
            if (isset($this->sortedSets[$key][$member])) {
                unset($this->sortedSets[$key][$member]);
                $removed++;
            }
        }

        return $removed;
    }

    public function zcard(string $key): int
    {
        $this->tally('zcard', [$key]);

        return count($this->sortedSets[$key] ?? []);
    }

    public function rpush(string $key, string ...$values): int
    {
        $this->tally('rpush', [$key, ...$values]);
        $this->lists[$key] = array_values(array_merge($this->lists[$key] ?? [], $values));

        return count($this->lists[$key]);
    }

    /** @return list<string> */
    public function lrange(string $key, int $start, int $stop): array
    {
        $this->tally('lrange', [$key, $start, $stop]);
        $values = $this->lists[$key] ?? [];
        $count = count($values);

        if ($count === 0) {
            return [];
        }

        $start = $start < 0 ? max(0, $count + $start) : $start;
        $stop = $stop < 0 ? $count + $stop : min($count - 1, $stop);

        if ($start > $stop) {
            return [];
        }

        return array_slice($values, $start, $stop - $start + 1);
    }

    public function ltrim(string $key, int $start, int $stop): bool
    {
        $this->tally('ltrim', [$key, $start, $stop]);
        $this->lists[$key] = $this->lrange($key, $start, $stop);

        return true;
    }

    public function llen(string $key): int
    {
        $this->tally('llen', [$key]);

        return count($this->lists[$key] ?? []);
    }

    public function expire(string $key, int $seconds): bool
    {
        $this->tally('expire', [$key, $seconds]);
        $this->ttls[$key] = $seconds;

        return true;
    }

    public function ttl(string $key): int
    {
        $this->tally('ttl', [$key]);

        return $this->ttls[$key] ?? -2;
    }

    public function exists(string $key): int
    {
        $this->tally('exists', [$key]);

        return isset($this->hashes[$key]) || isset($this->sortedSets[$key]) || isset($this->lists[$key])
            ? 1
            : 0;
    }

    public function del(string ...$keys): int
    {
        $this->tally('del', $keys);
        $removed = 0;

        foreach ($keys as $key) {
            $existed = $this->exists($key) === 1;
            unset($this->hashes[$key], $this->sortedSets[$key], $this->lists[$key], $this->ttls[$key]);

            if ($existed) {
                $removed++;
            }
        }

        return $removed;
    }

    /** @param array<int|string, mixed> $arguments */
    private function tally(string $method, array $arguments): void
    {
        $this->callCounts[$method] = ($this->callCounts[$method] ?? 0) + 1;
        $this->calls[] = [$method, $arguments];
    }

    private function scoreBoundary(string $value, float $default): float
    {
        $trimmed = ltrim($value, '(');

        return match ($trimmed) {
            '-inf' => -INF,
            '+inf', 'inf' => INF,
            default => is_numeric($trimmed) ? (float) $trimmed : $default,
        };
    }

    /** @return list<array{0: string, 1: array<int|string, mixed>}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call[0] === $method,
        ));
    }
}
