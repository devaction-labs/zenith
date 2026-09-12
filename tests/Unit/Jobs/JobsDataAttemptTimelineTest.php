<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Telemetry\AttemptHistory;
use DevactionLabs\Zenith\Telemetry\Data\JobAttemptData;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\dashboardThrows;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function DevactionLabs\Zenith\Tests\Support\telemetryRedis;

it('reports attempt history unavailable when the recorder is disabled', function (): void {
    config()->set('zenith.telemetry.enabled', false);

    ['redis' => $redis] = telemetryRedis();
    $repository = mockDashboardContract(JobRepository::class);
    $data = new JobsData($repository, attemptHistory: new AttemptHistory($redis));

    $timeline = $data->attemptTimeline('job-1');

    expect($timeline->available)->toBeFalse();
    expect($timeline->attempts)->toBe([]);
    expect($timeline->message)->not->toBeNull();
});

it('reports attempt history unavailable when no attempt history reader was injected', function (): void {
    config()->set('zenith.telemetry.enabled', true);

    $repository = mockDashboardContract(JobRepository::class);
    $data = new JobsData($repository);

    expect($data->attemptTimeline('job-1')->available)->toBeFalse();
});

it('returns recorded attempts when the recorder is enabled', function (): void {
    config()->set('zenith.telemetry.enabled', true);

    ['redis' => $redis] = telemetryRedis();
    $history = new AttemptHistory($redis);
    $history->record('job-1', new JobAttemptData(
        attempt: 1,
        outcome: 'processed',
        exceptionClass: null,
        message: null,
        fingerprint: null,
        runtimeMilliseconds: 42,
        node: 'node-1',
        occurredAt: 1_784_281_000,
    ));

    $repository = mockDashboardContract(JobRepository::class);
    $data = new JobsData($repository, attemptHistory: $history);

    $timeline = $data->attemptTimeline('job-1');

    expect($timeline->available)->toBeTrue();
    expect($timeline->attempts)->toHaveCount(1);
    expect($timeline->attempts[0]->node)->toBe('node-1');
});

it('reports attempt history unavailable and reports the failure when redis is unreachable', function (): void {
    config()->set('zenith.telemetry.enabled', true);

    $redis = mockDashboardContract(RedisFactory::class);
    dashboardThrows($redis, 'connection', new RuntimeException('redis is down'));

    $repository = mockDashboardContract(JobRepository::class);
    $data = new JobsData($repository, attemptHistory: new AttemptHistory($redis));

    $timeline = $data->attemptTimeline('job-1');

    expect($timeline->available)->toBeFalse();
    expect($timeline->message)->not->toBeNull();
});
