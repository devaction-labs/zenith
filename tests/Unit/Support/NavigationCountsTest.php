<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Batches\BatchRepositoryOverview;
use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\Queues\QueuePauseStatus;
use DevactionLabs\Zenith\Queues\QueuesData;
use DevactionLabs\Zenith\Queues\QueueWaitThreshold;
use DevactionLabs\Zenith\Support\NavigationCounts;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;

use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\dashboardThrows;
use function DevactionLabs\Zenith\Tests\Support\dashboardThrowsFor;
use function DevactionLabs\Zenith\Tests\Support\horizonBatch;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

it('collects bounded navigation counts from Horizon storage', function (): void {
    expect(navigationCounts()->get()->toArray())->toBe([
        'instances' => 2,
        'monitoring' => 2,
        'metrics' => 7,
        'queues' => 3,
        'batches' => 4,
        'pending' => 5,
        'completed' => 36,
        'silenced' => 3,
        'failed' => 6,
    ]);
});

it('isolates a failed monitoring count', function (): void {
    expect(navigationCounts(monitoringFails: true)->get()->toArray())->toBe([
        'instances' => 2,
        'monitoring' => null,
        'metrics' => 7,
        'queues' => 3,
        'batches' => 4,
        'pending' => 5,
        'completed' => 36,
        'silenced' => 3,
        'failed' => 6,
    ]);
});

it('isolates a failed batch count', function (): void {
    expect(navigationCounts(batchFails: true)->get()->toArray())->toBe([
        'instances' => 2,
        'monitoring' => 2,
        'metrics' => 7,
        'queues' => 3,
        'batches' => null,
        'pending' => 5,
        'completed' => 36,
        'silenced' => 3,
        'failed' => 6,
    ]);
});

it('counts batches through the configured repository', function (): void {
    expect(navigationCounts()->get()->batches)->toBe(4);
});

it('counts every retained batch page for navigation', function (): void {
    expect(navigationCounts()->get()->batches)->toBe(4);
});

it('returns a null batch navigation count without reporting when the database batch table is missing', function (): void {
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    config()->set('zenith.poll_interval', 0);
    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');

    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        app('db')->connection(),
        'job_batches',
    );
    app()->instance(BatchRepository::class, $repository);
    app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($repository));

    Exceptions::fake();

    $counts = navigationCounts(withDefaultBatches: false)->get();

    expect($counts->batches)->toBeNull();
    Exceptions::assertNothingReported();
});

/**
 * @param  bool  $withDefaultBatches  When false, uses the container BatchRepository binding.
 */
function navigationCounts(
    bool $monitoringFails = false,
    bool $batchFails = false,
    bool $withDefaultBatches = true,
): NavigationCounts {
    config()->set('queue.batching.database', null);
    config()->set('zenith.poll_interval', 0);

    $redisConnection = mockDashboardContract(Connection::class);

    if ($monitoringFails) {
        dashboardThrowsFor(
            $redisConnection,
            'scard',
            ['monitoring'],
            new RuntimeException('monitoring unavailable'),
        );
    } else {
        dashboardReturnsFor($redisConnection, 'scard', ['monitoring'], 2);
    }

    dashboardReturnsFor($redisConnection, 'scard', ['measured_jobs'], 4);
    dashboardReturnsFor($redisConnection, 'scard', ['measured_queues'], 3);
    $redis = mockDashboardContract(RedisFactory::class);
    dashboardReturns($redis, 'connection', $redisConnection);

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countPending', 5);
    dashboardReturns($jobs, 'countCompleted', 36);
    dashboardReturns($jobs, 'countSilenced', 3);
    dashboardReturns($jobs, 'countFailed', 6);
    $masters = mockDashboardContract(MasterSupervisorRepository::class);
    dashboardReturns($masters, 'all', [
        (object) ['name' => 'horizon-web-01'],
        (object) ['name' => 'horizon-worker-01'],
    ]);
    $supervisors = mockDashboardContract(SupervisorRepository::class);
    dashboardReturns($supervisors, 'all', [
        (object) ['processes' => ['redis:default,reports' => 2]],
        (object) ['processes' => ['redis:reports,batches' => 1]],
    ]);
    $queues = new QueuesData(
        $supervisors,
        mockDashboardContract(QueueFactory::class),
        mockDashboardContract(WaitTimeCalculator::class),
        mockDashboardContract(MetricsRepository::class),
        app(QueuePauseStatus::class),
        app(QueueWaitThreshold::class),
    );

    if ($withDefaultBatches) {
        $batches = mockDashboardContract(BatchRepository::class);

        if ($batchFails) {
            dashboardThrows($batches, 'get', new RuntimeException('batch repository unavailable'));
        } else {
            dashboardReturnsUsing(
                $batches,
                'get',
                static fn (int $limit, ?string $before): array => match ($before) {
                    null => array_slice([
                        horizonBatch('batch-4'),
                        horizonBatch('batch-3'),
                        horizonBatch('batch-2'),
                        horizonBatch('batch-1'),
                    ], 0, $limit),
                    'batch-1' => [],
                    default => throw new LogicException("Unexpected batch cursor [{$before}]."),
                },
            );
        }
    } else {
        $batches = app(BatchRepository::class);
    }

    return new NavigationCounts(
        $redis,
        $jobs,
        new BatchRepositoryOverview($batches, app(CacheFactory::class)),
        $queues,
        $masters,
        new DatabaseBatchCapability($batches),
    );
}
