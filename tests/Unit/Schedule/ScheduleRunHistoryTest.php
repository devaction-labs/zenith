<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Schedule\Data\ScheduleRunData;
use DevactionLabs\Zenith\Schedule\ScheduleRunHistory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

beforeEach(function (): void {
    app(CacheFactory::class)->store()->clear();
});

function scheduleRun(
    string $status = 'success',
    float $startedAt = 1_700_000_000.0,
    ?float $durationMs = 12.5,
    ?int $exitCode = 0,
    ?string $outputTail = null,
): ScheduleRunData {
    return new ScheduleRunData(
        status: $status,
        startedAt: $startedAt,
        durationMs: $durationMs,
        exitCode: $exitCode,
        outputTail: $outputTail,
    );
}

it('returns no history for an event that has never run', function (): void {
    expect(app(ScheduleRunHistory::class)->for('missing'))->toBe([]);
});

it('records a run and returns it back with the same fields', function (): void {
    $history = app(ScheduleRunHistory::class);
    $history->record('event-1', scheduleRun(status: 'failed', exitCode: 1, outputTail: 'boom'));

    $runs = $history->for('event-1');

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe('failed')
        ->and($runs[0]->exitCode)->toBe(1)
        ->and($runs[0]->outputTail)->toBe('boom');
});

it('keeps the most recent run first', function (): void {
    $history = app(ScheduleRunHistory::class);
    $history->record('event-1', scheduleRun(startedAt: 1.0));
    $history->record('event-1', scheduleRun(startedAt: 2.0));
    $history->record('event-1', scheduleRun(startedAt: 3.0));

    $runs = $history->for('event-1');

    expect(array_map(fn (ScheduleRunData $run): float => $run->startedAt, $runs))
        ->toBe([3.0, 2.0, 1.0]);
});

it('bounds history to the configured limit', function (): void {
    config()->set('zenith.schedule_history.limit', 2);
    $history = app(ScheduleRunHistory::class);

    $history->record('event-1', scheduleRun(startedAt: 1.0));
    $history->record('event-1', scheduleRun(startedAt: 2.0));
    $history->record('event-1', scheduleRun(startedAt: 3.0));

    $runs = $history->for('event-1');

    expect($runs)->toHaveCount(2)
        ->and(array_map(fn (ScheduleRunData $run): float => $run->startedAt, $runs))
        ->toBe([3.0, 2.0]);
});

it('keeps history for different events separate', function (): void {
    $history = app(ScheduleRunHistory::class);
    $history->record('event-1', scheduleRun(status: 'success'));
    $history->record('event-2', scheduleRun(status: 'failed'));

    expect($history->for('event-1')[0]->status)->toBe('success')
        ->and($history->for('event-2')[0]->status)->toBe('failed');
});
