<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

use Illuminate\Cache\RateLimiter;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
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
    /** @var array<string, list<Lock>> */
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
                $this->heldSlots[$name][] = $lock;

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
        $lock = array_pop($held);

        if ($held === []) {
            unset($this->heldSlots[$name]);
        } else {
            $this->heldSlots[$name] = $held;
        }

        $lock?->release();
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
    private function lockProvider(): LockProvider
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Zenith concurrency slots require a cache store that supports atomic locks.');
        }

        return $store;
    }
}
