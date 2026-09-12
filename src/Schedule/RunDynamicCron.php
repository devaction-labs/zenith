<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunDynamicCron implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $cronId,
    ) {}

    /**
     * Build the row's job with its payload as constructor arguments and
     * dispatch it, so it runs with its own connection, queue, and middleware.
     */
    public function handle(Container $container, Dispatcher $bus): void
    {
        $cron = DynamicCron::query()->find($this->cronId);

        if ($cron === null) {
            return;
        }

        $bus->dispatch($container->make($cron->job_class, $cron->payload ?? []));
    }
}
