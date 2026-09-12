<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Telemetry\JobIdentity;
use DevactionLabs\Zenith\Telemetry\TelemetryDimension;
use DevactionLabs\Zenith\Telemetry\TelemetryGroupBy;
use DevactionLabs\Zenith\Telemetry\TelemetryMetric;
use DevactionLabs\Zenith\Telemetry\TelemetryMetricsReader;
use DevactionLabs\Zenith\Telemetry\TelemetryOutcome;
use DevactionLabs\Zenith\Telemetry\TelemetryRecorder;
use DevactionLabs\Zenith\Telemetry\TelemetryWindow;
use DevactionLabs\Zenith\Telemetry\WorkerIdentity;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

use function DevactionLabs\Zenith\Tests\Support\dashboardThrows;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function DevactionLabs\Zenith\Tests\Support\telemetryRedis;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reports unavailable with a message when telemetry is disabled', function (): void {
    config()->set('zenith.telemetry.enabled', false);

    ['redis' => $redis] = telemetryRedis();
    $reader = new TelemetryMetricsReader($redis);

    $throughput = $reader->throughput(TelemetryWindow::OneHour, TelemetryGroupBy::State);
    $percentiles = $reader->percentiles(TelemetryWindow::OneHour, TelemetryMetric::Runtime, TelemetryDimension::Queue, 'default');

    expect($throughput->available)->toBeFalse();
    expect($throughput->series)->toBe([]);
    expect($percentiles->available)->toBeFalse();
    expect($percentiles->points)->toBe([]);
});

it('groups throughput by outcome across every queue, class, and node', function (): void {
    config()->set('zenith.telemetry.enabled', true);
    CarbonImmutable::setTestNow('2026-01-01 00:00:00 UTC');

    ['redis' => $redis] = telemetryRedis();
    $recorder = new TelemetryRecorder($redis);

    $recorder->record(
        TelemetryOutcome::Processed,
        new JobIdentity('emails', 'App\\Jobs\\SendEmail'),
        new WorkerIdentity('node-1', null),
        100,
        10,
    );
    $recorder->record(
        TelemetryOutcome::Failed,
        new JobIdentity('imports', 'App\\Jobs\\ImportFeed'),
        new WorkerIdentity('node-2', null),
        200,
        20,
    );

    $chart = (new TelemetryMetricsReader($redis))->throughput(TelemetryWindow::OneHour, TelemetryGroupBy::State);

    expect($chart->available)->toBeTrue();
    $labels = array_map(static fn ($series) => $series->label, $chart->series);
    expect($labels)->toContain('Processed', 'Failed');

    $processed = array_values(array_filter($chart->series, static fn ($series) => $series->label === 'Processed'))[0];
    expect(array_sum(array_map(static fn ($point) => $point->count, $processed->points)))->toBe(1);

    $failed = array_values(array_filter($chart->series, static fn ($series) => $series->label === 'Failed'))[0];
    expect(array_sum(array_map(static fn ($point) => $point->count, $failed->points)))->toBe(1);
});

it('groups throughput by the busiest values of the chosen dimension', function (): void {
    config()->set('zenith.telemetry.enabled', true);
    CarbonImmutable::setTestNow('2026-01-01 00:00:00 UTC');

    ['redis' => $redis] = telemetryRedis();
    $recorder = new TelemetryRecorder($redis);

    foreach (['emails', 'emails', 'emails', 'imports'] as $queue) {
        $recorder->record(
            TelemetryOutcome::Processed,
            new JobIdentity($queue, 'App\\Jobs\\Generic'),
            new WorkerIdentity('node-1', null),
            10,
            10,
        );
    }

    $chart = (new TelemetryMetricsReader($redis))->throughput(TelemetryWindow::OneHour, TelemetryGroupBy::Queue);

    expect($chart->available)->toBeTrue();
    expect($chart->series)->toHaveCount(2);

    $totals = [];

    foreach ($chart->series as $series) {
        $totals[$series->label] = array_sum(array_map(static fn ($point) => $point->count, $series->points));
    }

    expect($totals)->toBe(['emails' => 3, 'imports' => 1]);
});

it('caps dimension based grouping to the busiest five values', function (): void {
    config()->set('zenith.telemetry.enabled', true);
    CarbonImmutable::setTestNow('2026-01-01 00:00:00 UTC');

    ['redis' => $redis] = telemetryRedis();
    $recorder = new TelemetryRecorder($redis);

    foreach (range(1, 7) as $index) {
        $recorder->record(
            TelemetryOutcome::Processed,
            new JobIdentity("queue-{$index}", 'App\\Jobs\\Generic'),
            new WorkerIdentity('node-1', null),
            10,
            10,
        );
    }

    $chart = (new TelemetryMetricsReader($redis))->throughput(TelemetryWindow::OneHour, TelemetryGroupBy::Queue);

    expect($chart->series)->toHaveCount(5);
});

it('computes percentiles scoped to a specific dimension value', function (): void {
    config()->set('zenith.telemetry.enabled', true);
    CarbonImmutable::setTestNow('2026-01-01 00:00:00 UTC');

    ['redis' => $redis] = telemetryRedis();
    $recorder = new TelemetryRecorder($redis);

    foreach ([50, 50, 50, 5_000] as $runtimeMs) {
        $recorder->record(
            TelemetryOutcome::Processed,
            new JobIdentity('emails', 'App\\Jobs\\SendEmail'),
            new WorkerIdentity('node-1', null),
            $runtimeMs,
            null,
        );
    }

    // A different queue's runtime must not leak into the "emails" scoped percentile.
    $recorder->record(
        TelemetryOutcome::Processed,
        new JobIdentity('imports', 'App\\Jobs\\ImportFeed'),
        new WorkerIdentity('node-1', null),
        999_999,
        null,
    );

    $chart = (new TelemetryMetricsReader($redis))->percentiles(
        TelemetryWindow::OneHour,
        TelemetryMetric::Runtime,
        TelemetryDimension::Queue,
        'emails',
    );

    expect($chart->available)->toBeTrue();
    expect($chart->points)->toHaveCount(1);

    $p50 = $chart->points[0]->p50;
    expect($p50)->not->toBeNull();

    if ($p50 === null) {
        throw new RuntimeException('Expected a non-null p50 estimate.');
    }

    expect($p50)->toBeLessThan(1_000);
    expect($chart->points[0]->p99)->toBeGreaterThan($p50);
});

it('reports unavailable and logs the failure when redis is unreachable', function (): void {
    config()->set('zenith.telemetry.enabled', true);

    $redis = mockDashboardContract(RedisFactory::class);
    dashboardThrows($redis, 'connection', new RuntimeException('redis is down'));

    $reader = new TelemetryMetricsReader($redis);

    expect($reader->throughput(TelemetryWindow::OneHour, TelemetryGroupBy::State)->available)->toBeFalse();
    expect($reader->percentiles(TelemetryWindow::OneHour, TelemetryMetric::Runtime, TelemetryDimension::Queue, 'default')->available)
        ->toBeFalse();
});
