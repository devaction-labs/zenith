<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Schedule;

use DevactionLabs\Zenith\Schedule\Data\ScheduleRunData;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Records the outcome of every scheduled event run into ScheduleRunHistory,
 * listening to the events Laravel's own `schedule:run` command dispatches.
 *
 * `ScheduleRunCommand` always dispatches ScheduledTaskFinished once an event
 * returns, including a non-zero exit code; it dispatches ScheduledTaskFailed
 * afterwards only when that non-zero exit is then turned into an exception (or
 * when the event itself threw before finishing). The finished handler is
 * therefore authoritative whenever the event's `exitCode` was set, and the
 * failed handler only records a run that never reached that point.
 */
final readonly class ScheduleHistoryRecorder
{
    private const int OUTPUT_TAIL_BYTES = 2000;

    public function __construct(
        private ScheduleRunHistory $history,
    ) {}

    public function finished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;

        if (InternalScheduledEvent::matches($task->description)) {
            return;
        }

        $exitCode = $task->exitCode;

        $this->history->record(ScheduleCatalog::identify($task), new ScheduleRunData(
            status: $exitCode === null || $exitCode === 0 ? 'success' : 'failed',
            startedAt: Date::now()->getTimestamp() - $event->runtime,
            durationMs: $event->runtime * 1000,
            exitCode: $exitCode,
            outputTail: $this->outputTail($task),
        ));
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $task = $event->task;

        if ($task->exitCode !== null || InternalScheduledEvent::matches($task->description)) {
            return;
        }

        $this->history->record(ScheduleCatalog::identify($task), new ScheduleRunData(
            status: 'failed',
            startedAt: (float) Date::now()->getTimestamp(),
            durationMs: null,
            exitCode: null,
            outputTail: Str::limit($event->exception->getMessage(), 500),
        ));
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $task = $event->task;

        if (InternalScheduledEvent::matches($task->description)) {
            return;
        }

        $this->history->record(ScheduleCatalog::identify($task), new ScheduleRunData(
            status: 'skipped',
            startedAt: (float) Date::now()->getTimestamp(),
            durationMs: null,
            exitCode: null,
            outputTail: null,
        ));
    }

    private function outputTail(Event $task): ?string
    {
        if ($task->output === $task->getDefaultOutput() || ! is_file($task->output)) {
            return null;
        }

        $contents = trim((string) @file_get_contents($task->output));

        return $contents === '' ? null : Str::substr($contents, -self::OUTPUT_TAIL_BYTES);
    }
}
