<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Chains\ChainSequencer;
use DevactionLabs\Zenith\Tests\Unit\Signals\InterleavingArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use LogicException;

final class LockFreeStore implements Store
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get($key): mixed
    {
        return $this->items[$key] ?? null;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        return array_fill_keys($keys, null);
    }

    public function put($key, $value, $seconds): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    /** @param array<string, mixed> $values */
    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->items[$key] = $value;
        }

        return true;
    }

    public function increment($key, $value = 1): int
    {
        $current = $this->items[$key] ?? 0;
        $amount = is_numeric($value) ? (int) $value : 1;
        $this->items[$key] = (is_numeric($current) ? (int) $current : 0) + $amount;

        return $this->items[$key];
    }

    public function decrement($key, $value = 1): int
    {
        $amount = is_numeric($value) ? (int) $value : 1;

        return $this->increment($key, -$amount);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function touch($key, $seconds): bool
    {
        return true;
    }

    public function forget($key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->items = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}

it('assigns strictly increasing tickets per key starting at one', function (): void {
    expect(ChainSequencer::assignTicket('orders'))->toBe(1)
        ->and(ChainSequencer::assignTicket('orders'))->toBe(2)
        ->and(ChainSequencer::assignTicket('orders'))->toBe(3);
});

it('keeps ticket sequences independent per key', function (): void {
    expect(ChainSequencer::assignTicket('orders'))->toBe(1)
        ->and(ChainSequencer::assignTicket('invoices'))->toBe(1)
        ->and(ChainSequencer::assignTicket('orders'))->toBe(2);
});

it('treats the first ticket as next until the chain advances', function (): void {
    ChainSequencer::assignTicket('orders');
    ChainSequencer::assignTicket('orders');

    expect(ChainSequencer::isNext('orders', 1))->toBeTrue()
        ->and(ChainSequencer::isNext('orders', 2))->toBeFalse();

    ChainSequencer::advance('orders', 1);

    expect(ChainSequencer::isNext('orders', 1))->toBeFalse()
        ->and(ChainSequencer::isNext('orders', 2))->toBeTrue();
});

it('ignores an advance call for a ticket that is not currently held', function (): void {
    ChainSequencer::assignTicket('orders');
    ChainSequencer::assignTicket('orders');

    ChainSequencer::advance('orders', 2);

    expect(ChainSequencer::isNext('orders', 1))->toBeTrue();
});

it('requires a cache store that supports atomic locks', function (): void {
    $cache = app(CacheManager::class);
    $cache->extend('no-locks', fn (): Repository => $cache->repository(new LockFreeStore));
    config()->set('cache.stores.no-locks', ['driver' => 'no-locks']);
    config()->set('zenith.chains.store', 'no-locks');

    expect(fn (): int => ChainSequencer::assignTicket('orders'))
        ->toThrow(LogicException::class, 'Zenith chains require a cache store that supports atomic locks.');
});

it('never assigns the same ticket to concurrently dispatched jobs sharing a key', function (): void {
    InterleavingArrayStore::register('chain-interleave');
    config()->set('zenith.chains.store', 'chain-interleave');

    $tickets = [];
    $assign = function () use (&$tickets): void {
        $tickets[] = ChainSequencer::assignTicket('races');
    };

    $lockWaits = InterleavingArrayStore::interleave([$assign, $assign, $assign]);

    sort($tickets);

    expect($tickets)->toBe([1, 2, 3])
        ->and($lockWaits)->toBeGreaterThan(0);
});
