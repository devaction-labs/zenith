<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Metrics\SnapshotJobsPerMinute;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\dashboardThrows;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('projects jobs per minute from fractional elapsed minutes since the last snapshot', function (): void {
    CarbonImmutable::setTestNow('2026-07-18 12:00:00 UTC');

    expect(SnapshotJobsPerMinute::calculate(
        processedSinceSnapshot: 100,
        lastSnapshotAt: (string) CarbonImmutable::now()->subSeconds(30)->getTimestamp(),
    ))->toBe(200.0);

    expect(SnapshotJobsPerMinute::calculate(
        processedSinceSnapshot: 250,
        lastSnapshotAt: (string) CarbonImmutable::now()->subMinutes(20)->getTimestamp(),
    ))->toBe(12.5);
});

it('returns zero when the snapshot timestamp is missing invalid or not yet elapsed', function (mixed $lastSnapshotAt): void {
    CarbonImmutable::setTestNow('2026-07-18 12:00:00 UTC');

    expect(SnapshotJobsPerMinute::calculate(
        processedSinceSnapshot: 40,
        lastSnapshotAt: $lastSnapshotAt,
    ))->toBe(0);
})->with([
    'missing' => null,
    'non-numeric' => 'not-a-timestamp',
    'future' => fn () => (string) CarbonImmutable::now()->addMinute()->getTimestamp(),
    'zero elapsed' => fn () => (string) CarbonImmutable::now()->getTimestamp(),
]);

it('reads last_snapshot_at from the horizon redis connection', function (): void {
    CarbonImmutable::setTestNow('2026-07-18 12:00:00 UTC');

    $connection = mockDashboardContract(Connection::class);
    dashboardReturnsFor(
        $connection,
        'get',
        ['last_snapshot_at'],
        (string) CarbonImmutable::now()->subSeconds(30)->getTimestamp(),
    );
    $redis = mockDashboardContract(RedisFactory::class);
    dashboardReturns($redis, 'connection', $connection);

    $projector = new SnapshotJobsPerMinute($redis);

    expect($projector->project(100))->toBe(200.0);
});

it('returns zero and reports when redis fails', function (): void {
    $redis = mockDashboardContract(RedisFactory::class);
    dashboardThrows($redis, 'connection', new RuntimeException('redis secret'));

    $projector = new SnapshotJobsPerMinute($redis);

    expect($projector->project(100))->toBe(0);
});
