<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use DevactionLabs\Zenith\Schedule\Data\ScheduleEventData;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Throwable;

final readonly class ScheduleCatalog
{
    public function __construct(
        private Schedule $schedule,
    ) {}

    /**
     * @return list<ScheduleEventData>
     */
    public function events(): array
    {
        $events = [];

        foreach ($this->schedule->events() as $event) {
            $events[] = $this->row($event);
        }

        return $events;
    }

    public function event(string $id): ?Event
    {
        foreach ($this->schedule->events() as $event) {
            if ($this->id($event) === $id) {
                return $event;
            }
        }

        return null;
    }

    private function row(Event $event): ScheduleEventData
    {
        $nextRunAt = null;

        try {
            $nextRunAt = (float) $event->nextRunDate()->format('U.u');
        } catch (Throwable) {
            $nextRunAt = null;
        }

        $timezone = $this->timezone($event->timezone);

        return new ScheduleEventData(
            id: $this->id($event),
            expression: $event->getExpression(),
            description: $event->getSummaryForDisplay(),
            command: is_string($event->command) && $event->command !== '' ? $event->command : null,
            timezone: $timezone,
            nextRunAt: $nextRunAt,
            withoutOverlapping: $event->withoutOverlapping,
            onOneServer: $event->onOneServer,
            evenInMaintenanceMode: $event->evenInMaintenanceMode,
            runInBackground: $event->runInBackground,
            overlapping: $this->overlapping($event),
            runtimeEditable: false,
        );
    }

    private function id(Event $event): string
    {
        return hash(
            'xxh3',
            $event->getExpression()."\0".$event->getSummaryForDisplay()."\0".($this->timezone($event->timezone) ?? ''),
        );
    }

    private function timezone(mixed $timezone): ?string
    {
        if ($timezone instanceof \DateTimeZone) {
            return $timezone->getName();
        }

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    private function overlapping(Event $event): bool
    {
        if (! $event->withoutOverlapping) {
            return false;
        }

        try {
            return $event->mutex->exists($event);
        } catch (Throwable) {
            return false;
        }
    }
}
