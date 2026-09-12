<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Signals;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * Named, single-use signals that let queued jobs wait for an external decision.
 *
 * Signals live for `zenith.signals.ttl` seconds in the cache store named by
 * `zenith.signals.store`, or the default store when it is null. Use a shared,
 * persistent store such as Redis or the database so every worker sees them.
 */
final class Signal
{
    private const string PAYLOAD_PREFIX = 'zenith:signal:';

    private const string DEADLINE_PREFIX = 'zenith:signal-deadline:';

    private const string LOCK_PREFIX = 'zenith:signal-lock:';

    private const int LOCK_SECONDS = 10;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function send(string $name, array $payload = []): void
    {
        self::store()->put(
            self::PAYLOAD_PREFIX.$name,
            $payload,
            Config::integer('zenith.signals.ttl', 86400),
        );
    }

    /**
     * Consume a signal; concurrent consumers never receive the same signal twice.
     *
     * @return array<string, mixed>|null
     */
    public static function pull(string $name): ?array
    {
        $store = self::store();
        $payload = self::exclusively(
            $store,
            $name,
            static fn (): mixed => $store->pull(self::PAYLOAD_PREFIX.$name),
        );

        return is_array($payload)
            ? array_filter($payload, is_string(...), ARRAY_FILTER_USE_KEY)
            : null;
    }

    /**
     * Consume a signal, waiting for it by releasing the running queue job.
     *
     * When the signal is absent and the caller runs inside a queued job (exposed by the
     * ReleaseWhileWaiting middleware as Illuminate\Contracts\Queue\Job), a positive
     * $retryAfter releases that job for $retryAfter seconds and throws SignalWaiting, so
     * the job is redelivered later instead of occupying a worker. The deadline counts
     * $seconds from the first wait for the signal and survives redeliveries. Once it has
     * passed, or when there is no job to release, SignalTimeoutException is thrown.
     *
     * Redeliveries count as attempts: give waiting jobs a retryUntil() deadline instead
     * of a fixed $tries so waiting never exhausts them.
     *
     * @return array<string, mixed>
     *
     * @throws SignalWaiting
     * @throws SignalTimeoutException
     */
    public static function await(string $name, int $seconds = 30, int $retryAfter = 0): array
    {
        $store = self::store();
        $deadline = self::DEADLINE_PREFIX.$name;
        $payload = self::pull($name);

        if ($payload !== null) {
            $store->forget($deadline);

            return $payload;
        }

        $wait = QueuedWait::current($retryAfter);

        if ($wait === null) {
            throw new SignalTimeoutException(
                "Signal [{$name}] has not arrived and there is no queued job to release while waiting.",
            );
        }

        if ($wait->hasTimedOut($store, $deadline, $seconds)) {
            $store->forget($deadline);

            throw new SignalTimeoutException("Signal [{$name}] did not arrive within {$seconds} seconds.");
        }

        $wait->release();

        throw new SignalWaiting("Waiting for signal [{$name}]; the job was released for {$retryAfter} seconds.");
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private static function exclusively(Repository $store, string $name, Closure $callback): mixed
    {
        $locks = $store->getStore();

        if (! $locks instanceof LockProvider) {
            throw new LogicException('Zenith signals require a cache store that supports atomic locks.');
        }

        return $locks->lock(self::LOCK_PREFIX.$name, self::LOCK_SECONDS)->block(self::LOCK_SECONDS, $callback);
    }

    private static function store(): Repository
    {
        $store = config('zenith.signals.store');

        return app(Factory::class)->store(is_string($store) ? $store : null);
    }
}
