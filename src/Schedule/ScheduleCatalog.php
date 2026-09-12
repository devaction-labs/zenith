<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use DevactionLabs\Zenith\Schedule\Data\ScheduleEventData;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Throwable;

final readonly class ScheduleCatalog
{
    private const string DYNAMIC_ID_PREFIX = 'dynamic-';

    public function __construct(
        private Schedule $schedule,
        private DynamicSchedule $dynamic,
    ) {}

    /**
     * @return list<ScheduleEventData>
     */
    public function events(): array
    {
        $events = [];

        foreach ($this->applicationEvents() as $event) {
            $events[] = $this->row($event);
        }

        foreach ($this->dynamic->events() as $cron) {
            $events[] = $this->dynamicRow($cron);
        }

        return $events;
    }

    public function event(string $id): ?Event
    {
        foreach ($this->applicationEvents() as $event) {
            if ($this->id($event) === $id) {
                return $event;
            }
        }

        return null;
    }

    public function dynamicCron(string $id): ?DynamicCron
    {
        if (! str_starts_with($id, self::DYNAMIC_ID_PREFIX)) {
            return null;
        }

        $key = substr($id, strlen(self::DYNAMIC_ID_PREFIX));

        return ctype_digit($key) ? $this->dynamic->find((int) $key) : null;
    }

    /**
     * @return list<Event>
     */
    private function applicationEvents(): array
    {
        return array_values(array_filter(
            $this->schedule->events(),
            static fn (Event $event): bool => InternalScheduledEvent::tryFrom($event->description ?? '') === null,
        ));
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
            paused: false,
        );
    }

    private function dynamicRow(DynamicCron $cron): ScheduleEventData
    {
        $nextRunDate = $cron->paused ? null : $cron->nextRunDate(Date::now());

        return new ScheduleEventData(
            id: self::DYNAMIC_ID_PREFIX.$cron->id,
            expression: $cron->expression,
            description: $cron->name,
            command: $cron->job_class,
            timezone: $cron->effectiveTimezone(),
            nextRunAt: $nextRunDate !== null ? (float) $nextRunDate->format('U.u') : null,
            withoutOverlapping: false,
            onOneServer: true,
            evenInMaintenanceMode: false,
            runInBackground: false,
            overlapping: false,
            runtimeEditable: true,
            paused: $cron->paused,
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
