<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Telemetry\InFlightJobTracker;
use DevactionLabs\Zenith\Telemetry\TelemetryEventSubscriber;
use DevactionLabs\Zenith\Telemetry\TelemetryKeys;
use DevactionLabs\Zenith\Telemetry\TelemetryRecorder;
use DevactionLabs\Zenith\Tests\Support\TelemetryRedisConnection;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;

use function DevactionLabs\Zenith\Tests\Support\telemetryFakeJob;
use function DevactionLabs\Zenith\Tests\Support\telemetryRedis;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, string> */
function telemetryHashFields(TelemetryRedisConnection $connection): array
{
    return array_merge(...array_values($connection->hashes === [] ? [[]] : $connection->hashes));
}

function telemetrySubscriber(RedisFactory $redis): TelemetryEventSubscriber
{
    return new TelemetryEventSubscriber(new TelemetryRecorder($redis), new InFlightJobTracker($redis));
}

it('records a processed outcome with runtime and wait timings', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 00:05:00 UTC');

    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);

    $job = telemetryFakeJob(['createdAt' => CarbonImmutable::now()->subSeconds(2)->getTimestamp()]);

    $subscriber->handleProcessing(new JobProcessing('redis', $job));
    $subscriber->handleAttempted(new JobAttempted('redis', $job, null));

    $fields = telemetryHashFields($connection);

    expect($fields["count\x1fqueue\x1fdefault\x1fprocessed"] ?? null)->toBe('1');
    expect(array_filter(array_keys($fields), static fn (string $field): bool => str_starts_with($field, "hist\x1fwait\x1f")))
        ->not->toBeEmpty();
});

it('records a failed outcome when the job has failed', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);

    $job = telemetryFakeJob();
    $job->markFailedForTest();

    $subscriber->handleProcessing(new JobProcessing('redis', $job));
    $subscriber->handleAttempted(new JobAttempted('redis', $job, new RuntimeException('boom')));

    $fields = telemetryHashFields($connection);

    expect($fields["count\x1fqueue\x1fdefault\x1ffailed"] ?? null)->toBe('1');
});

it('records a released outcome when the job released itself without failing', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);

    $job = telemetryFakeJob();
    $job->markReleasedForTest();

    $subscriber->handleProcessing(new JobProcessing('redis', $job));
    $subscriber->handleAttempted(new JobAttempted('redis', $job, null));

    $fields = telemetryHashFields($connection);

    expect($fields["count\x1fqueue\x1fdefault\x1freleased"] ?? null)->toBe('1');
});

it('records a timed out outcome and never dispatches a matching attempted event', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);

    $job = telemetryFakeJob();

    $subscriber->handleProcessing(new JobProcessing('redis', $job));
    $subscriber->handleTimedOut(new JobTimedOut('redis', $job, 60));

    $fields = telemetryHashFields($connection);

    expect($fields["count\x1fqueue\x1fdefault\x1ftimed_out"] ?? null)->toBe('1');
});

it('still records an outcome without runtime or wait when processing was never observed', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);

    $job = telemetryFakeJob();

    $subscriber->handleAttempted(new JobAttempted('redis', $job, null));

    $fields = telemetryHashFields($connection);

    expect($fields["count\x1fqueue\x1fdefault\x1fprocessed"] ?? null)->toBe('1');
    expect(array_filter(array_keys($fields), static fn (string $field): bool => str_starts_with($field, 'hist')))
        ->toBeEmpty();
});

it('does not leak pending timings between different jobs', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);

    $first = telemetryFakeJob(['uuid' => 'job-a']);
    $second = telemetryFakeJob(['uuid' => 'job-b']);

    $subscriber->handleProcessing(new JobProcessing('redis', $first));
    $subscriber->handleAttempted(new JobAttempted('redis', $first, null));
    $subscriber->handleAttempted(new JobAttempted('redis', $second, null));

    $fields = telemetryHashFields($connection);

    expect($fields["count\x1fqueue\x1fdefault\x1fprocessed"] ?? null)->toBe('2');
});

it('tracks the job as in-flight from processing until its attempted event', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);

    $job = telemetryFakeJob(['uuid' => 'job-in-flight']);

    $subscriber->handleProcessing(new JobProcessing('redis', $job));

    expect($connection->hashes[TelemetryKeys::inFlightJob('job-in-flight')] ?? [])->not->toBe([]);

    $subscriber->handleAttempted(new JobAttempted('redis', $job, null));

    expect($connection->hashes[TelemetryKeys::inFlightJob('job-in-flight')] ?? [])->toBe([]);
});

it('clears the in-flight entry when a job times out instead of attempting', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);

    $job = telemetryFakeJob(['uuid' => 'job-timed-out']);

    $subscriber->handleProcessing(new JobProcessing('redis', $job));
    $subscriber->handleTimedOut(new JobTimedOut('redis', $job, 60));

    expect($connection->hashes[TelemetryKeys::inFlightJob('job-timed-out')] ?? [])->toBe([]);
    expect($connection->sortedSets[TelemetryKeys::inFlightIndex()] ?? [])->not->toHaveKey('job-timed-out');
});
