<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chunks;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;

final readonly class ChunkBuffer
{
    public function __construct(
        private Repository $cache,
        private Dispatcher $bus,
    ) {}

    /**
     * @param  class-string  $job
     * @param  array<string, mixed>  $item
     */
    public function push(string $name, string $job, array $item, int $size = 100): void
    {
        $key = 'zenith:chunk:'.$name;
        $items = $this->cache->get($key, []);
        $items = is_array($items) ? $items : [];
        $items[] = $item;

        if (count($items) < $size) {
            $this->cache->put($key, $items, 3600);

            return;
        }

        $this->cache->forget($key);
        $this->bus->dispatch(new RunChunkJob($job, array_values($items)));
    }
}
