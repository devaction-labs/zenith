<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Monitoring\Actions;

use DevactionLabs\HorizonNewDawn\Monitoring\MonitoringTagGuard;
use Illuminate\Contracts\Bus\Dispatcher;
use Laravel\Horizon\Contracts\TagRepository;
use Laravel\Horizon\Jobs\StopMonitoringTag as HorizonStopMonitoringTag;

final readonly class StopMonitoringTag
{
    public function __construct(
        private Dispatcher $bus,
        private TagRepository $tags,
        private MonitoringTagGuard $guard,
    ) {}

    public function handle(string $tag): void
    {
        $this->guard->ensureMonitored($this->tags, $tag);

        $this->bus->dispatch(new HorizonStopMonitoringTag($tag));
    }
}
