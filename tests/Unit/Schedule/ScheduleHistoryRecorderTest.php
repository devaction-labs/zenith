<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Schedule\ScheduleCatalog;
use DevactionLabs\Zenith\Schedule\ScheduleRunHistory;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventFacade;

beforeEach(function (): void {
    app(CacheFactory::class)->store()->clear();
    $this->travelTo(Date::parse('2026-09-12 12:00:00 UTC'));
});

function scheduleEventNamed(string $description): Event
{
    $event = app(Schedule::class)->call(static fn () => null)->description($description);

    return $event;
}

it('records a successful run when a task finishes with a zero exit code', function (): void {
    $event = scheduleEventNamed('Probe success');
    $event->exitCode = 0;

    EventFacade::dispatch(new ScheduledTaskFinished($event, 1.25));

    $runs = app(ScheduleRunHistory::class)->for(ScheduleCatalog::identify($event));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe('success')
        ->and($runs[0]->exitCode)->toBe(0)
        ->and($runs[0]->durationMs)->toBe(1250.0);
});

it('records a failed run when a task finishes with a non-zero exit code', function (): void {
    $event = scheduleEventNamed('Probe non-zero');
    $event->exitCode = 1;

    EventFacade::dispatch(new ScheduledTaskFinished($event, 0.5));

    $runs = app(ScheduleRunHistory::class)->for(ScheduleCatalog::identify($event));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe('failed')
        ->and($runs[0]->exitCode)->toBe(1);
});

it('does not double-record a run that finished with a non-zero exit and then failed', function (): void {
    $event = scheduleEventNamed('Probe finished then failed');
    $event->exitCode = 1;

    EventFacade::dispatch(new ScheduledTaskFinished($event, 0.5));
    EventFacade::dispatch(new ScheduledTaskFailed($event, new RuntimeException('non-zero exit')));

    $runs = app(ScheduleRunHistory::class)->for(ScheduleCatalog::identify($event));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe('failed');
});

it('records a run that threw before finishing, with the exception message as its tail', function (): void {
    $event = scheduleEventNamed('Probe threw');

    EventFacade::dispatch(new ScheduledTaskFailed($event, new RuntimeException('kaboom')));

    $runs = app(ScheduleRunHistory::class)->for(ScheduleCatalog::identify($event));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe('failed')
        ->and($runs[0]->durationMs)->toBeNull()
        ->and($runs[0]->outputTail)->toBe('kaboom');
});

it('records a skipped run', function (): void {
    $event = scheduleEventNamed('Probe skipped');

    EventFacade::dispatch(new ScheduledTaskSkipped($event));

    $runs = app(ScheduleRunHistory::class)->for(ScheduleCatalog::identify($event));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe('skipped')
        ->and($runs[0]->exitCode)->toBeNull();
});

it('ignores Zenith internal scheduler ticks', function (): void {
    $event = scheduleEventNamed('zenith:dynamic-crons');
    $event->exitCode = 0;

    EventFacade::dispatch(new ScheduledTaskFinished($event, 0.1));

    expect(app(ScheduleRunHistory::class)->for(ScheduleCatalog::identify($event)))->toBe([]);
});

it('reads a short output tail when the event redirects output to a file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'zenith-schedule-output');
    file_put_contents($path, str_repeat('x', 3000)."\ndone");

    $event = scheduleEventNamed('Probe with output')->sendOutputTo($path);
    $event->exitCode = 0;

    EventFacade::dispatch(new ScheduledTaskFinished($event, 0.1));

    $runs = app(ScheduleRunHistory::class)->for(ScheduleCatalog::identify($event));

    expect($runs[0]->outputTail)->not->toBeNull()
        ->and($runs[0]->outputTail)->toEndWith('done')
        ->and(mb_strlen((string) $runs[0]->outputTail))->toBeLessThanOrEqual(2000);

    unlink($path);
});
