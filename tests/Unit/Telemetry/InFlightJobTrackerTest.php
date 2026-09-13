<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Telemetry\InFlightJobTracker;
use DevactionLabs\Zenith\Telemetry\JobIdentity;
use DevactionLabs\Zenith\Telemetry\TelemetryKeys;
use DevactionLabs\Zenith\Telemetry\WorkerIdentity;

use function DevactionLabs\Zenith\Tests\Support\telemetryRedis;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('stores the job details and indexes it by start time', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 00:00:00 UTC');

    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $tracker = new InFlightJobTracker($redis);

    $tracker->start(
        jobId: 'job-uuid-1',
        job: new JobIdentity(queue: 'default', jobClass: 'App\\Jobs\\ImportFeed', connection: 'redis'),
        worker: new WorkerIdentity(node: 'node-1', supervisor: 'supervisor-1'),
        timeoutSeconds: 30,
    );

    $key = TelemetryKeys::inFlightJob('job-uuid-1');

    expect($connection->hashes[$key])->toMatchArray([
        'queue' => 'default',
        'class' => 'App\\Jobs\\ImportFeed',
        'node' => 'node-1',
        'supervisor' => 'supervisor-1',
        'startedAt' => (string) CarbonImmutable::now()->getTimestamp(),
        'timeoutSeconds' => '30',
    ]);
    expect($connection->sortedSets[TelemetryKeys::inFlightIndex()]['job-uuid-1'] ?? null)
        ->toBe((float) CarbonImmutable::now()->getTimestamp());
    // Timeout (30s) + grace (default 60s).
    expect($connection->ttls[$key])->toBe(90);
});

it('stores an empty supervisor and timeout when neither is known', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $tracker = new InFlightJobTracker($redis);

    $tracker->start(
        jobId: 'job-uuid-2',
        job: new JobIdentity(queue: 'default', jobClass: 'App\\Jobs\\ImportFeed', connection: 'redis'),
        worker: new WorkerIdentity(node: 'node-1', supervisor: null),
        timeoutSeconds: null,
    );

    $key = TelemetryKeys::inFlightJob('job-uuid-2');

    expect($connection->hashes[$key]['supervisor'])->toBe('');
    expect($connection->hashes[$key]['timeoutSeconds'])->toBe('');
    // Falls back to the configured default timeout (60s) + grace (60s).
    expect($connection->ttls[$key])->toBe(120);
});

it('removes the job entry and its index member when finished', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $tracker = new InFlightJobTracker($redis);

    $tracker->start(
        jobId: 'job-uuid-3',
        job: new JobIdentity(queue: 'default', jobClass: 'App\\Jobs\\ImportFeed', connection: 'redis'),
        worker: new WorkerIdentity(node: 'node-1', supervisor: null),
        timeoutSeconds: 30,
    );

    $tracker->finish('job-uuid-3');

    expect($connection->hashes[TelemetryKeys::inFlightJob('job-uuid-3')] ?? [])->toBe([]);
    expect($connection->sortedSets[TelemetryKeys::inFlightIndex()] ?? [])->not->toHaveKey('job-uuid-3');
});
