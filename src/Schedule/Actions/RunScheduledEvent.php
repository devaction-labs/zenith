<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule\Actions;

use DevactionLabs\Zenith\Schedule\ScheduleCatalog;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final readonly class RunScheduledEvent
{
    public function __construct(
        private ScheduleCatalog $catalog,
        private Application $application,
    ) {}

    public function handle(string $id): string
    {
        $event = $this->catalog->event($id);

        if ($event === null) {
            throw new RuntimeException('Scheduled event not found.');
        }

        $event->run($this->application);

        return $event->getSummaryForDisplay();
    }
}
