<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

use Illuminate\Contracts\Cache\Repository;

final readonly class QueueBudget
{
    public function __construct(
        private Repository $cache,
    ) {}

    public function allows(string $name, int $allowed, int $period, int $weight = 1, ?string $partition = null): bool
    {
        $used = (int) $this->cache->get($this->key($name, $partition), 0);

        return ($used + $weight) <= $allowed;
    }

    public function consume(string $name, int $allowed, int $period, int $weight = 1, ?string $partition = null): bool
    {
        if (! $this->allows($name, $allowed, $period, $weight, $partition)) {
            return false;
        }

        $key = $this->key($name, $partition);
        $used = (int) $this->cache->get($key, 0);
        $this->cache->put($key, $used + $weight, $period);

        return true;
    }

    private function key(string $name, ?string $partition): string
    {
        return 'zenith:budget:'.$name.($partition !== null ? ':'.$partition : '');
    }
}
