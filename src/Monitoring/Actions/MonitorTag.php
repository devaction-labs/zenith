<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Monitoring\Actions;

use DevactionLabs\HorizonNewDawn\Monitoring\MonitoringTagGuard;
use Illuminate\Contracts\Bus\Dispatcher;
use Laravel\Horizon\Jobs\MonitorTag as HorizonMonitorTag;

final readonly class MonitorTag
{
    public function __construct(
        private Dispatcher $bus,
        private MonitoringTagGuard $guard,
    ) {}

    public function handle(string $tag): void
    {
        $this->guard->ensureSafe($tag);

        $this->bus->dispatch(new HorizonMonitorTag($tag));
    }
}
