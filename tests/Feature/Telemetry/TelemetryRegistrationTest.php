<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobTimedOut;

it('registers no telemetry listeners in a default application boot', function (): void {
    expect(config('zenith.telemetry.enabled'))->toBeFalse();

    $events = app(Dispatcher::class);

    // Nothing but the telemetry recorder is interested in JobTimedOut, so
    // any listener on it in a default (disabled) boot would have to be ours.
    expect($events->hasListeners(JobTimedOut::class))->toBeFalse();
});
