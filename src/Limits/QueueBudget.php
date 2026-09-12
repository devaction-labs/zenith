<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

use Illuminate\Cache\RateLimiter;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Global queue budgets shared by every worker through the cache.
 *
 * Rate budgets run on Laravel's rate limiter: a fixed window opens on the
 * first hit and resets after the period however busy the queue is, and the
 * counter is incremented atomically before it is compared with the budget.
 * Concurrency slots are atomic cache locks, so any lock-capable store enforces
 * them across workers. The budget is scoped to the container, so a job
 * acquires and releases its slots on the same instance.
 */
#[Scoped]
final class QueueBudget
{
    /** @var array<string, list<array{lock: Lock, slot: int}>> */
    private array $heldSlots = [];

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Repository $cache,
    ) {}

    public function allows(string $name, int $allowed, int $period, int $weight = 1, ?string $partition = null): bool
    {
        return $this->limiter->remaining($this->key($name, $partition), $allowed) >= $weight;
    }

    public function consume(string $name, int $allowed, int $period, int $weight = 1, ?string $partition = null): bool
    {
        if (! $this->allows($name, $allowed, $period, $weight, $partition)) {
            return false;
        }

        $key = $this->key($name, $partition);

        if ($this->limiter->increment($key, $period, $weight) <= $allowed) {
            return true;
        }

        $this->limiter->decrement($key, $period, $weight);

        return false;
    }

    /**
     * Get the number of seconds until the current rate window resets.
     */
    public function availableIn(string $name, ?string $partition = null): int
    {
        return $this->limiter->availableIn($this->key($name, $partition));
    }

    /**
     * The number of hits left in the current rate window before $allowed is reached,
     * without consuming one.
     */
    public function remaining(string $name, int $allowed, ?string $partition = null): int
    {
        return max(0, $this->limiter->remaining($this->key($name, $partition), $allowed));
    }

    /**
     * Acquire one of the `$limit` concurrency slots for the name. A slot
     * expires after `$expiresAfter` seconds so a crashed worker cannot hold it
     * forever.
     *
     * @throws RuntimeException
     */
    public function acquireSlot(string $name, int $limit, int $expiresAfter = 60): bool
    {
        $store = $this->lockProvider();
        $owner = Str::random(20);

        for ($slot = 1; $slot <= $limit; $slot++) {
            $lock = $store->lock($this->slotKey($name, $slot), $expiresAfter, $owner);

            if ($lock->get() === true) {
                $store->put($this->slotKey($name, $slot), true, $expiresAfter);
                $this->heldSlots[$name][] = ['lock' => $lock, 'slot' => $slot];

                return true;
            }
        }

        return false;
    }

    /**
     * Release the most recent slot this budget acquired for the name. Slots
     * held by other workers are never released.
     */
    public function releaseSlot(string $name): void
    {
        $held = $this->heldSlots[$name] ?? [];
        $slot = array_pop($held);

        if ($held === []) {
            unset($this->heldSlots[$name]);
        } else {
            $this->heldSlots[$name] = $held;
        }

        if ($slot === null) {
            return;
        }

        $this->lockProvider()->forget($this->slotKey($name, $slot['slot']));
        $slot['lock']->release();
    }

    /**
     * The number of the name's $limit concurrency slots currently held by any worker.
     *
     * The lock itself proves atomicity but some stores, such as the array store, keep
     * their lock state outside the normal get/put keyspace. The plain marker written
     * alongside each acquired lock in acquireSlot() is what makes usage inspectable
     * here regardless of the underlying store.
     *
     * @throws RuntimeException
     */
    public function slotsInUse(string $name, int $limit): int
    {
        $store = $this->lockProvider();
        $inUse = 0;

        for ($slot = 1; $slot <= $limit; $slot++) {
            if ($store->get($this->slotKey($name, $slot)) !== null) {
                $inUse++;
            }
        }

        return $inUse;
    }

    private function key(string $name, ?string $partition): string
    {
        return 'zenith:budget:'.$name.($partition !== null ? ':'.$partition : '');
    }

    private function slotKey(string $name, int $slot): string
    {
        return 'zenith:budget-slot:'.$name.':'.$slot;
    }

    /**
     * @throws RuntimeException
     */
    private function lockProvider(): Store&LockProvider
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Zenith concurrency slots require a cache store that supports atomic locks.');
        }

        return $store;
    }
}
