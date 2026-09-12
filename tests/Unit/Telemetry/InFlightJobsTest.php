<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Telemetry\InFlightJobs;
use DevactionLabs\Zenith\Telemetry\InFlightJobTracker;
use DevactionLabs\Zenith\Telemetry\JobIdentity;
use DevactionLabs\Zenith\Telemetry\TelemetryKeys;
use DevactionLabs\Zenith\Telemetry\WorkerIdentity;

use function DevactionLabs\Zenith\Tests\Support\telemetryRedis;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reports unavailable with a message when telemetry is disabled', function (): void {
    config()->set('zenith.telemetry.enabled', false);

    ['redis' => $redis] = telemetryRedis();

    $page = (new InFlightJobs($redis))->list();

    expect($page->available)->toBeFalse();
    expect($page->jobs)->toBe([]);
    expect($page->message)->not->toBeNull();
});

it('lists running jobs sorted by elapsed time and flags overrunning ones', function (): void {
    config()->set('zenith.telemetry.enabled', true);
    CarbonImmutable::setTestNow('2026-01-01 00:00:00 UTC');

    ['redis' => $redis] = telemetryRedis();
    $tracker = new InFlightJobTracker($redis);

    $tracker->start(
        jobId: 'job-a',
        job: new JobIdentity(queue: 'default', jobClass: 'App\\Jobs\\Short'),
        worker: new WorkerIdentity(node: 'node-1', supervisor: 'supervisor-1'),
        timeoutSeconds: 120,
    );

    CarbonImmutable::setTestNow('2026-01-01 00:00:10 UTC');
    $tracker->start(
        jobId: 'job-b',
        job: new JobIdentity(queue: 'default', jobClass: 'App\\Jobs\\Overrunning'),
        worker: new WorkerIdentity(node: 'node-2', supervisor: null),
        timeoutSeconds: 5,
    );

    CarbonImmutable::setTestNow('2026-01-01 00:00:20 UTC');

    $page = (new InFlightJobs($redis))->list();

    expect($page->available)->toBeTrue();
    expect($page->jobs)->toHaveCount(2);

    // Sorted with the longest-running job first.
    expect($page->jobs[0]->id)->toBe('job-a');
    expect($page->jobs[0]->elapsedSeconds)->toBe(20);
    expect($page->jobs[0]->overrunning)->toBeFalse();

    expect($page->jobs[1]->id)->toBe('job-b');
    expect($page->jobs[1]->elapsedSeconds)->toBe(10);
    expect($page->jobs[1]->timeoutSeconds)->toBe(5);
    expect($page->jobs[1]->overrunning)->toBeTrue();

    expect($page->nodeSummary)->toHaveCount(2);
    expect($page->nodeSummary[0]->node)->toBe('node-1');
    expect($page->nodeSummary[0]->count)->toBe(1);
    expect($page->nodeSummary[1]->node)->toBe('node-2');
    expect($page->nodeSummary[1]->count)->toBe(1);
});

it('self heals by dropping index members whose entry already expired', function (): void {
    config()->set('zenith.telemetry.enabled', true);

    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $connection->sortedSets[TelemetryKeys::inFlightIndex()] = ['ghost-job' => 100.0];

    $page = (new InFlightJobs($redis))->list();

    expect($page->available)->toBeTrue();
    expect($page->jobs)->toBe([]);
    expect($connection->sortedSets[TelemetryKeys::inFlightIndex()])->not->toHaveKey('ghost-job');
});
