<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Telemetry\JobIdentity;
use DevactionLabs\Zenith\Telemetry\TelemetryOutcome;
use DevactionLabs\Zenith\Telemetry\TelemetryRecorder;
use DevactionLabs\Zenith\Telemetry\WorkerIdentity;

use function DevactionLabs\Zenith\Tests\Support\telemetryRedis;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('increments a bounded, fixed number of redis fields per recorded attempt', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 00:00:30 UTC');

    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();

    (new TelemetryRecorder($redis))->record(
        outcome: TelemetryOutcome::Processed,
        job: new JobIdentity(queue: 'default', jobClass: 'App\\Jobs\\ImportFeed', connection: 'redis'),
        worker: new WorkerIdentity(node: 'node-1', supervisor: 'supervisor-1'),
        runtimeMilliseconds: 250,
        waitMilliseconds: 1500,
    );

    // 3 resolutions x 4 dimensions x (1 count + 1 runtime histogram + 1 wait histogram) field.
    expect($connection->callCounts['hincrby'] ?? 0)->toBe(36);
    // 3 resolutions x 1 index trim / add / expire.
    expect($connection->callCounts['zremrangebyscore'] ?? 0)->toBe(3);
    expect($connection->callCounts['zadd'] ?? 0)->toBe(3);
    // 3 resolutions x (1 index expire + 1 bucket expire).
    expect($connection->callCounts['expire'] ?? 0)->toBe(6);

    $fields = array_keys(array_merge(...array_values($connection->hashes)));

    expect($fields)->toContain("count\x1fqueue\x1fdefault\x1fprocessed");
    expect($fields)->toContain("count\x1fclass\x1fApp\\Jobs\\ImportFeed\x1fprocessed");
    expect($fields)->toContain("count\x1fnode\x1fnode-1\x1fprocessed");
    expect($fields)->toContain("count\x1fconnection\x1fredis\x1fprocessed");

    foreach ($connection->hashes as $hash) {
        foreach ($hash as $value) {
            expect($value)->toBe('1');
        }
    }
});

it('skips histogram fields but still records the outcome when timings are unavailable', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 00:00:30 UTC');

    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();

    (new TelemetryRecorder($redis))->record(
        outcome: TelemetryOutcome::TimedOut,
        job: new JobIdentity(queue: 'default', jobClass: 'App\\Jobs\\ImportFeed', connection: 'redis'),
        worker: new WorkerIdentity(node: 'node-1', supervisor: null),
        runtimeMilliseconds: null,
        waitMilliseconds: null,
    );

    // 3 resolutions x 4 dimensions x 1 count field only.
    expect($connection->callCounts['hincrby'] ?? 0)->toBe(12);

    $fields = array_keys(array_merge(...array_values($connection->hashes)));

    foreach ($fields as $field) {
        expect($field)->toStartWith('count');
    }
});

it('writes each resolution into its own bucket key with the correct ttl', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 00:00:30 UTC');

    ['redis' => $redis, 'connection' => $connection] = telemetryRedis();

    (new TelemetryRecorder($redis))->record(
        outcome: TelemetryOutcome::Processed,
        job: new JobIdentity(queue: 'default', jobClass: 'App\\Jobs\\ImportFeed', connection: 'redis'),
        worker: new WorkerIdentity(node: 'node-1', supervisor: null),
        runtimeMilliseconds: 10,
        waitMilliseconds: 10,
    );

    $expireTtls = array_map(static fn (array $call): mixed => $call[1][1], $connection->callsTo('expire'));

    expect($expireTtls)->toContain(900);
    expect($expireTtls)->toContain(86_400);
    expect($expireTtls)->toContain(604_800);

    $bucketKeys = array_keys($connection->hashes);

    expect($bucketKeys)->toHaveCount(3);

    foreach ($bucketKeys as $bucketKey) {
        expect($bucketKey)->toContain('telemetry:bucket:');
    }
});
