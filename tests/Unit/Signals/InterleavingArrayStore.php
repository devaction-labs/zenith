<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Tests\Unit\Signals;

use Closure;
use Fiber;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Sleep;

/**
 * Array cache store whose reads and writes yield to other fibers, so simulated
 * concurrent callers interleave inside each other's read-modify-write cycles.
 * Waiting for a held lock yields as well, handing control back to the holder.
 */
final class InterleavingArrayStore extends ArrayStore
{
    public static function register(string $name): void
    {
        $cache = app(CacheManager::class);
        $store = new self;

        $cache->extend($name, fn (): Repository => $cache->repository($store));
        config()->set("cache.stores.{$name}", ['driver' => $name]);
    }

    /**
     * Run every caller in its own fiber, round-robin, until all of them finish.
     *
     * @param  list<Closure(): void>  $callers
     * @return int The number of times a caller had to wait for a lock.
     */
    public static function interleave(array $callers): int
    {
        $lockWaits = 0;

        Sleep::fake();
        Sleep::whenFakingSleep(static function () use (&$lockWaits): void {
            if (Fiber::getCurrent() !== null) {
                $lockWaits++;
                Fiber::suspend();
            }
        });

        try {
            $fibers = array_map(static fn (Closure $caller): Fiber => new Fiber($caller), $callers);

            while ($fibers !== []) {
                foreach ($fibers as $index => $fiber) {
                    if ($fiber->isStarted()) {
                        $fiber->resume();
                    } else {
                        $fiber->start();
                    }

                    if ($fiber->isTerminated()) {
                        unset($fibers[$index]);
                    }
                }
            }
        } finally {
            Sleep::fake(false);
        }

        return $lockWaits;
    }

    /**
     * @param  string  $key
     */
    public function get($key): mixed
    {
        self::yieldToOtherCallers();

        return parent::get($key);
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     */
    public function put($key, $value, $seconds): bool
    {
        self::yieldToOtherCallers();

        return parent::put($key, $value, $seconds);
    }

    /**
     * @param  string  $key
     */
    public function forget($key): bool
    {
        self::yieldToOtherCallers();

        return parent::forget($key);
    }

    private static function yieldToOtherCallers(): void
    {
        if (Fiber::getCurrent() !== null) {
            Fiber::suspend();
        }
    }
}
