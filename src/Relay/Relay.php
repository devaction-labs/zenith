<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Relay;

use DevactionLabs\Zenith\Signals\QueuedWait;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as ArrayRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dispatch a job and await its result from anywhere.
 *
 * Results live for `zenith.relay.ttl` seconds in the cache store named by
 * `zenith.relay.store`, or the default store when it is null. With a Redis store,
 * awaiting callers block on a per-relay list instead of polling the cache.
 */
final class Relay
{
    private const string RESULT_PREFIX = 'zenith:relay:';

    private const string DEADLINE_PREFIX = 'zenith:relay-deadline:';

    private const string NOTIFICATION_PREFIX = 'zenith:relay-ready:';

    private const int FIRST_POLL_MILLISECONDS = 10;

    private const int MAX_POLL_MILLISECONDS = 250;

    private const int MAX_BLOCK_MILLISECONDS = 5000;

    private static ?Repository $fakeStore = null;

    /**
     * Route every relay result through an isolated, in-memory store instead of the cache
     * store named by zenith.relay.store, so a test can record and await relayed results
     * deterministically without configuring a shared cache. Call it again for a clean slate.
     */
    public static function fake(): void
    {
        self::$fakeStore = new ArrayRepository(new ArrayStore);
    }

    public static function async(object $job): string
    {
        $id = (string) Str::uuid();
        app(Dispatcher::class)->dispatch(new RunRelayedJob($id, $job));

        return $id;
    }

    /**
     * Await the result of a relayed job.
     *
     * Outside a queued job, or when $retryAfter is zero, the caller waits in place for up
     * to $seconds: it blocks on Redis when the relay store is Redis and otherwise polls
     * the cache with a growing delay. Inside a queued job (exposed by the
     * ReleaseWhileWaiting middleware) a positive $retryAfter releases the job for
     * $retryAfter seconds and throws RelayWaiting instead; that deadline counts $seconds
     * from the first wait and survives redeliveries.
     *
     * @throws RelayWaiting
     * @throws RelayTimeoutException
     * @throws RelayFailedException
     */
    public static function await(string $id, int $seconds = 30, int $retryAfter = 0): mixed
    {
        $store = self::store();
        $result = self::result($store, $id) ?? self::wait($store, $id, $seconds, $retryAfter);

        $store->forget(self::DEADLINE_PREFIX.$id);

        return self::unwrap($id, $result);
    }

    public static function record(string $id, mixed $value): void
    {
        self::put($id, ['value' => $value]);
    }

    public static function fail(string $id, string $message, string $exception = RuntimeException::class): void
    {
        self::put($id, ['exception' => $exception, 'message' => $message]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function wait(Repository $store, string $id, int $seconds, int $retryAfter): array
    {
        $wait = QueuedWait::current($retryAfter);

        if ($wait === null) {
            return self::block($store, $id, $seconds) ?? throw self::timeout($id, $seconds);
        }

        if ($wait->hasTimedOut($store, self::DEADLINE_PREFIX.$id, $seconds)) {
            $store->forget(self::DEADLINE_PREFIX.$id);

            throw self::timeout($id, $seconds);
        }

        $wait->release();

        throw new RelayWaiting("Waiting for relay [{$id}]; the job was released for {$retryAfter} seconds.");
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function block(Repository $store, string $id, int $seconds): ?array
    {
        $deadline = Date::now()->getTimestampMs() + $seconds * 1000;
        $pause = self::FIRST_POLL_MILLISECONDS;

        while (true) {
            $result = self::result($store, $id);

            if ($result !== null) {
                return $result;
            }

            $remaining = $deadline - Date::now()->getTimestampMs();

            if ($remaining <= 0) {
                return null;
            }

            $redis = $store->getStore();

            if ($redis instanceof RedisStore) {
                self::listen($redis, $id, min($remaining, self::MAX_BLOCK_MILLISECONDS));

                continue;
            }

            Sleep::usleep(min($pause, $remaining) * 1000);
            $pause = min($pause * 2, self::MAX_POLL_MILLISECONDS);
        }
    }

    private static function listen(RedisStore $store, string $id, int $milliseconds): void
    {
        $notification = $store->connection()->command('blpop', [
            self::notificationKey($store, $id),
            $milliseconds / 1000,
        ]);

        if (is_array($notification) && $notification !== []) {
            self::notify($store, $id);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function put(string $id, array $result): void
    {
        $store = self::store();

        $store->put(self::RESULT_PREFIX.$id, $result, self::ttl());

        $redis = $store->getStore();

        if ($redis instanceof RedisStore) {
            self::notify($redis, $id);
        }
    }

    private static function notify(RedisStore $store, string $id): void
    {
        $key = self::notificationKey($store, $id);

        $store->connection()->command('rpush', [$key, 1]);
        $store->connection()->command('expire', [$key, self::ttl()]);
    }

    private static function notificationKey(RedisStore $store, string $id): string
    {
        return $store->getPrefix().self::NOTIFICATION_PREFIX.$id;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function result(Repository $store, string $id): ?array
    {
        $result = $store->get(self::RESULT_PREFIX.$id);

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<array-key, mixed>  $result
     */
    private static function unwrap(string $id, array $result): mixed
    {
        if (array_key_exists('value', $result)) {
            return $result['value'];
        }

        $exception = $result['exception'] ?? null;
        $message = $result['message'] ?? $result['error'] ?? null;

        throw new RelayFailedException(
            $id,
            is_string($exception) ? $exception : RuntimeException::class,
            is_string($message) ? $message : "Relay [{$id}] failed.",
        );
    }

    private static function timeout(string $id, int $seconds): RelayTimeoutException
    {
        return new RelayTimeoutException("Relay [{$id}] did not finish within {$seconds} seconds.");
    }

    private static function ttl(): int
    {
        return Config::integer('zenith.relay.ttl', 3600);
    }

    private static function store(): Repository
    {
        if (self::$fakeStore instanceof Repository) {
            return self::$fakeStore;
        }

        $store = config('zenith.relay.store');

        return app(Factory::class)->store(is_string($store) ? $store : null);
    }
}
