<?php

declare(strict_types=1);

use DevactionLabs\Zenith\History\JobHistoryStopwatch;

it('returns null when a timer was never started', function (): void {
    $stopwatch = new JobHistoryStopwatch;

    expect($stopwatch->checkAndForget('missing'))->toBeNull();
});

it('measures elapsed milliseconds between start and check', function (): void {
    $stopwatch = new JobHistoryStopwatch;

    $stopwatch->start('job-1');
    usleep(2_000);
    $elapsed = $stopwatch->checkAndForget('job-1');

    expect($elapsed)->not->toBeNull()
        ->and($elapsed)->toBeGreaterThanOrEqual(1);
});

it('forgets the timer after it has been checked', function (): void {
    $stopwatch = new JobHistoryStopwatch;

    $stopwatch->start('job-1');
    $stopwatch->checkAndForget('job-1');

    expect($stopwatch->checkAndForget('job-1'))->toBeNull();
});

it('tracks independent timers per key', function (): void {
    $stopwatch = new JobHistoryStopwatch;

    $stopwatch->start('job-1');
    $stopwatch->start('job-2');
    $stopwatch->checkAndForget('job-1');

    expect($stopwatch->checkAndForget('job-2'))->not->toBeNull();
});
