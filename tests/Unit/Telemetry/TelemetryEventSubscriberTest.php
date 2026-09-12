<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Telemetry\AttemptHistory;
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
    return new TelemetryEventSubscriber(
        new TelemetryRecorder($redis),
        new InFlightJobTracker($redis),
        new AttemptHistory($redis),
    );
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

it('shows three attempts with the right outcomes for a job that fails twice then succeeds', function (): void {
    ['redis' => $redis] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);
    $history = new AttemptHistory($redis);

    $job = telemetryFakeJob(['uuid' => 'job-flaky'], attempts: 1);
    $job->markFailedForTest();
    $subscriber->handleProcessing(new JobProcessing('redis', $job));
    $subscriber->handleAttempted(new JobAttempted('redis', $job, new RuntimeException('boom')));

    $job = telemetryFakeJob(['uuid' => 'job-flaky'], attempts: 2);
    $job->markFailedForTest();
    $subscriber->handleProcessing(new JobProcessing('redis', $job));
    $subscriber->handleAttempted(new JobAttempted('redis', $job, new RuntimeException('boom again')));

    $job = telemetryFakeJob(['uuid' => 'job-flaky'], attempts: 3);
    $subscriber->handleProcessing(new JobProcessing('redis', $job));
    $subscriber->handleAttempted(new JobAttempted('redis', $job, null));

    $attempts = $history->forJob('job-flaky');

    expect($attempts)->toHaveCount(3);
    expect(array_map(static fn ($attempt) => $attempt->outcome, $attempts))
        ->toBe(['failed', 'failed', 'processed']);
    expect($attempts[0]->exceptionClass)->toBe(RuntimeException::class);
    expect($attempts[0]->message)->toBe('boom');
    expect($attempts[0]->fingerprint)->not->toBeNull();
    expect($attempts[2]->exceptionClass)->toBeNull();
    expect($attempts[2]->message)->toBeNull();
});

it('records a timed out attempt without exception details', function (): void {
    ['redis' => $redis] = telemetryRedis();
    $subscriber = telemetrySubscriber($redis);
    $history = new AttemptHistory($redis);

    $job = telemetryFakeJob(['uuid' => 'job-stuck']);

    $subscriber->handleProcessing(new JobProcessing('redis', $job));
    $subscriber->handleTimedOut(new JobTimedOut('redis', $job, 60));

    $attempts = $history->forJob('job-stuck');

    expect($attempts)->toHaveCount(1);
    expect($attempts[0]->outcome)->toBe('timed_out');
    expect($attempts[0]->exceptionClass)->toBeNull();
});
