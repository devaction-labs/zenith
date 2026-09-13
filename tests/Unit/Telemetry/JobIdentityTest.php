<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\JobIdentity;

use function DevactionLabs\Zenith\Tests\Support\telemetryFakeJob;

it('resolves the queue and the underlying application job class', function (): void {
    $job = telemetryFakeJob();

    $identity = JobIdentity::fromJob($job);

    expect($identity->queue)->toBe('default');
    expect($identity->jobClass)->toBe('App\\Jobs\\ImportFeed');
});

it('resolves the connection the job was dispatched on', function (): void {
    $job = telemetryFakeJob(connectionName: 'deferred');

    $identity = JobIdentity::fromJob($job);

    expect($identity->connection)->toBe('deferred');
});

it('falls back to the raw job name when no command class is present', function (): void {
    $job = telemetryFakeJob([
        'displayName' => null,
        'job' => 'App\\Jobs\\LegacyHandler@handle',
        'data' => ['commandName' => null],
    ]);

    $identity = JobIdentity::fromJob($job);

    expect($identity->jobClass)->toBe('App\\Jobs\\LegacyHandler@handle');
});
