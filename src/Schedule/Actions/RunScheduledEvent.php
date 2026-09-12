<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule\Actions;

use DevactionLabs\Zenith\Schedule\RunDynamicCron;
use DevactionLabs\Zenith\Schedule\ScheduleCatalog;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final readonly class RunScheduledEvent
{
    public function __construct(
        private ScheduleCatalog $catalog,
        private Application $application,
        private Dispatcher $bus,
    ) {}

    /**
     * Run a scheduler event now, or queue a dynamic cron row, and return its description.
     *
     * @throws RuntimeException
     */
    public function handle(string $id): string
    {
        $cron = $this->catalog->dynamicCron($id);

        if ($cron !== null) {
            $this->bus->dispatch(new RunDynamicCron($cron->id));

            return $cron->name;
        }

        $event = $this->catalog->event($id);

        if ($event === null) {
            throw new RuntimeException('Scheduled event not found.');
        }

        $event->run($this->application);

        return $event->getSummaryForDisplay();
    }
}
