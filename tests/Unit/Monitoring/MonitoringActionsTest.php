<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Laravel\Horizon\Jobs\MonitorTag as HorizonMonitorTag;
use Laravel\Horizon\Jobs\RetryFailedJob as HorizonRetryFailedJob;
use Laravel\Horizon\Jobs\StopMonitoringTag as HorizonStopMonitoringTag;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;
use NckRtl\HorizonNewDawn\FailedJobs\Actions\RetryFailedJob;
use NckRtl\HorizonNewDawn\FailedJobs\FailedJobRetryEligibility;
use NckRtl\HorizonNewDawn\Monitoring\Actions\ClearRecentJobs;
use NckRtl\HorizonNewDawn\Monitoring\Actions\MonitorTag;
use NckRtl\HorizonNewDawn\Monitoring\Actions\RetryFailedJobs;
use NckRtl\HorizonNewDawn\Monitoring\Actions\StopMonitoringTag;
use NckRtl\HorizonNewDawn\Monitoring\MonitoringTagGuard;

use function NckRtl\HorizonNewDawn\Tests\Support\bulkSnapshotRedis;
use function NckRtl\HorizonNewDawn\Tests\Support\dashboardReturns;
use function NckRtl\HorizonNewDawn\Tests\Support\dashboardReturnsUsing;
use function NckRtl\HorizonNewDawn\Tests\Support\horizonJob;
use function NckRtl\HorizonNewDawn\Tests\Support\mockDashboardContract;

it('dispatches supported Horizon monitor-tag jobs', function (): void {
    Bus::fake();
    $tags = mockDashboardContract(TagRepository::class);
    dashboardReturns($tags, 'monitoring', ['checkout']);
    app()->instance(TagRepository::class, $tags);

    app(MonitorTag::class)->handle('checkout');
    app(StopMonitoringTag::class)->handle('checkout');

    Bus::assertDispatched(
        HorizonMonitorTag::class,
        fn (HorizonMonitorTag $job): bool => $job->tag === 'checkout',
    );
    Bus::assertDispatched(
        HorizonStopMonitoringTag::class,
        fn (HorizonStopMonitoringTag $job): bool => $job->tag === 'checkout',
    );
});

it('clears only the captured recent-job memberships without stopping monitoring or changing job retention', function (): void {
    $redis = bulkSnapshotRedis();
    $members = [];

    for ($index = 0; $index < 52; $index++) {
        $members["recent-{$index}"] = (float) -$index;
    }

    $redis->seedSortedSet('customer:42', $members);

    $tags = mockDashboardContract(TagRepository::class);
    dashboardReturns($tags, 'monitoring', ['customer:42']);
    $tags->shouldNotReceive('forget');

    $result = (new ClearRecentJobs(
        $tags,
        app(RedisFactory::class),
        new MonitoringTagGuard,
        app(BulkOperationSnapshot::class),
    ))->processChunk('customer:42');

    expect($result->complete)->toBeTrue()
        ->and($result->totalAffected)->toBe(52)
        ->and($redis->sortedSets['customer:42'] ?? [])->toBe([]);
    $tags->shouldNotHaveReceived('stopMonitoring');
});

it('rejects internal Horizon keys before clearing monitored jobs', function (string $tag): void {
    bulkSnapshotRedis();
    $tags = mockDashboardContract(TagRepository::class);
    dashboardReturns($tags, 'monitoring', [$tag]);
    $tags->shouldNotReceive('paginate');
    $tags->shouldNotReceive('forget');

    app()->instance(TagRepository::class, $tags);

    expect(fn () => app(ClearRecentJobs::class)->processChunk($tag))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'pending jobs index' => 'pending_jobs',
    'global job id counter' => 'job_id',
]);

it('rejects unmonitored tags before dispatching destructive Horizon jobs', function (): void {
    Bus::fake();
    $tags = mockDashboardContract(TagRepository::class);
    dashboardReturns($tags, 'monitoring', ['checkout']);
    app()->instance(TagRepository::class, $tags);

    expect(fn () => app(StopMonitoringTag::class)->handle('customer:42'))
        ->toThrow(InvalidArgumentException::class);

    Bus::assertNotDispatched(HorizonStopMonitoringTag::class);
});

it('retries eligible failed jobs for a monitored tag from a point-in-time snapshot', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);
    $redis = bulkSnapshotRedis();

    $ids = [];
    $jobsById = [];

    for ($index = 0; $index < 52; $index++) {
        $id = "failed-{$index}";
        $ids[$id] = (float) -$index;
        $jobsById[$id] = horizonJob($index, $id);
    }

    $retryJob = $jobsById['failed-1'];
    $retryPayload = json_decode($retryJob->payload, true, flags: JSON_THROW_ON_ERROR);
    $retryJob->payload = json_encode([...$retryPayload, 'retry_of' => 'original-1'], JSON_THROW_ON_ERROR);

    $retriedJob = $jobsById['failed-3'];
    $retriedJob->retried_by = json_encode([
        ['id' => 'retry-3', 'status' => 'completed', 'retried_at' => 1_784_281_100],
    ], JSON_THROW_ON_ERROR);

    $failedRetryJob = $jobsById['failed-4'];
    $failedRetryJob->retried_by = json_encode([
        ['id' => 'retry-4', 'status' => 'failed', 'retried_at' => 1_784_281_100],
    ], JSON_THROW_ON_ERROR);

    unset($jobsById['failed-2']);
    $redis->seedSortedSet('failed:customer:42', $ids);

    $tags = mockDashboardContract(TagRepository::class);
    dashboardReturns($tags, 'monitoring', ['customer:42']);

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsUsing($jobs, 'getJobs', function (array $requested) use ($jobsById): Collection {
        return new Collection(array_values(array_filter(
            array_map(static fn (string $id): ?object => $jobsById[$id] ?? null, $requested),
        )));
    });

    $result = (new RetryFailedJobs(
        $tags,
        $jobs,
        new RetryFailedJob(app(Dispatcher::class), $jobs, new FailedJobRetryEligibility),
        new MonitoringTagGuard,
        app(BulkOperationSnapshot::class),
    ))->processChunk('customer:42');

    expect($result->complete)->toBeTrue()
        ->and($result->totalAffected)->toBe(49);

    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, 49);
    Bus::assertDispatched(
        HorizonRetryFailedJob::class,
        fn (HorizonRetryFailedJob $job): bool => $job->id === 'failed-1',
    );
    Bus::assertNotDispatched(
        HorizonRetryFailedJob::class,
        fn (HorizonRetryFailedJob $job): bool => in_array($job->id, ['failed-2', 'failed-3', 'failed-4'], true),
    );
});
