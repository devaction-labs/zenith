<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chunks;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LogicException;

final class RunChunkJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  class-string  $jobClass
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public string $jobClass,
        public array $items,
    ) {}

    public function handle(Container $container): void
    {
        $job = $container->make($this->jobClass);

        if (! is_object($job) || ! method_exists($job, 'handle')) {
            throw new LogicException("Chunk job [{$this->jobClass}] must define a handle() method.");
        }

        $job->handle($this->items);
    }
}
