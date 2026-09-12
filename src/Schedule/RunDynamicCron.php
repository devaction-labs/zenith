<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

final class RunDynamicCron implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $cronId,
    ) {}

    /**
     * Build the row's job with its payload as constructor arguments and
     * dispatch it, so it runs with its own connection, queue, and middleware.
     *
     * Re-checks the job class against the configured allowlist at dispatch
     * time, so a row created before the allowlist changed can never dispatch
     * a class that is no longer allowed.
     */
    public function handle(Container $container, Dispatcher $bus, DynamicCronAllowlist $allowlist): void
    {
        $cron = DynamicCron::query()->find($this->cronId);

        if ($cron === null) {
            return;
        }

        if (! $allowlist->allows($cron->job_class)) {
            report(new RuntimeException(
                "Refused to dispatch dynamic cron [{$cron->name}]: job class [{$cron->job_class}] is not in the configured allowlist.",
            ));

            return;
        }

        $bus->dispatch($container->make($cron->job_class, $cron->payload ?? []));
    }
}
