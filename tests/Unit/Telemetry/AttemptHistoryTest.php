<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\AttemptHistory;
use DevactionLabs\Zenith\Telemetry\Data\JobAttemptData;
use DevactionLabs\Zenith\Telemetry\TelemetryKeys;

use function DevactionLabs\Zenith\Tests\Support\telemetryRedis;

function telemetryAttempt(int $attempt, string $outcome = 'processed'): JobAttemptData
{
    return new JobAttemptData(
        attempt: $attempt,
        outcome: $outcome,
        exceptionClass: $outcome === 'failed' ? 'RuntimeException' : null,
        message: $outcome === 'failed' ? 'boom' : null,
        fingerprint: $outcome === 'failed' ? 'abcd1234' : null,
        runtimeMilliseconds: 120,
        node: 'node-1',
        occurredAt: 1_784_281_000 + $attempt,
    );
}

it('appends attempts and reads them back in order', function (): void {
    ['redis' => $redis] = telemetryRedis();
    $history = new AttemptHistory($redis);

    $history->record('job-1', telemetryAttempt(1, 'failed'));
    $history->record('job-1', telemetryAttempt(2, 'failed'));
    $history->record('job-1', telemetryAttempt(3, 'processed'));

    $attempts = $history->forJob('job-1');

    expect($attempts)->toHaveCount(3);
    expect(array_map(static fn (JobAttemptData $attempt): int => $attempt->attempt, $attempts))
        ->toBe([1, 2, 3]);
    expect($attempts[0]->exceptionClass)->toBe('RuntimeException');
    expect($attempts[0]->message)->toBe('boom');
    expect($attempts[0]->fingerprint)->toBe('abcd1234');
    expect($attempts[2]->exceptionClass)->toBeNull();
});

it('trims history to the configured per-job limit, keeping the newest attempts', function (): void {
    config()->set('zenith.telemetry.attempts.per_job_limit', 2);

    ['redis' => $redis] = telemetryRedis();
    $history = new AttemptHistory($redis);

    $history->record('job-1', telemetryAttempt(1));
    $history->record('job-1', telemetryAttempt(2));
    $history->record('job-1', telemetryAttempt(3));

    $attempts = $history->forJob('job-1');

    expect(array_map(static fn (JobAttemptData $attempt): int => $attempt->attempt, $attempts))
        ->toBe([2, 3]);
});

it('sets a ttl on the attempts key from configuration', function (): void {
    config()->set('zenith.telemetry.attempts.ttl_seconds', 3600);

    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $history = new AttemptHistory($redis);

    $history->record('job-1', telemetryAttempt(1));

    expect($connection->ttls[TelemetryKeys::attempts('job-1')])->toBe(3600);
});

it('returns an empty list for a job with no recorded history', function (): void {
    ['redis' => $redis] = telemetryRedis();

    expect((new AttemptHistory($redis))->forJob('unknown-job'))->toBe([]);
});

it('ignores malformed entries instead of failing the whole read', function (): void {
    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();
    $connection->lists[TelemetryKeys::attempts('job-1')] = ['not-json', '{"attempt":1}'];

    expect((new AttemptHistory($redis))->forJob('job-1'))->toBe([]);
});
