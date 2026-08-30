<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Batches\BatchRepositoryOverview;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchCapability;
use DevactionLabs\HorizonNewDawn\Dashboard\DashboardBatchSummary;
use DevactionLabs\HorizonNewDawn\Dashboard\DashboardData;
use DevactionLabs\HorizonNewDawn\Dashboard\DashboardPendingState;
use DevactionLabs\HorizonNewDawn\Metrics\SnapshotJobsPerMinute;
use DevactionLabs\HorizonNewDawn\Queues\QueuePauseMetadata;
use DevactionLabs\HorizonNewDawn\Queues\QueuePauseStatus;
use DevactionLabs\HorizonNewDawn\Queues\QueueWaitThreshold;
use DevactionLabs\HorizonNewDawn\Support\HorizonRuntime;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\WaitTimeCalculator;

use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturns;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;
use function Pest\Laravel\get;

beforeEach(function (): void {
    Horizon::auth(static fn (): bool => true);

    $masters = mockDashboardContract(MasterSupervisorRepository::class);
    dashboardReturns($masters, 'all', [(object) ['name' => 'horizon-web-01', 'status' => 'running']]);
    app()->instance(HorizonRuntime::class, new HorizonRuntime($masters));
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
    Schema::dropIfExists('horizon_new_dawn_batch_metadata');
    Schema::dropIfExists('job_batches');
});

it('keeps the dashboard available while omitting batch summary when the batch table is missing', function (): void {
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    config()->set('horizon-new-dawn.poll_interval', 0);
    Schema::dropIfExists('horizon_new_dawn_batch_metadata');
    Schema::dropIfExists('job_batches');

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countFailed', 3);
    dashboardReturns($jobs, 'countCompleted', 36);
    dashboardReturns($jobs, 'countPending', 5);
    dashboardReturns($jobs, 'countRecentlyFailed', 2);
    dashboardReturns($jobs, 'countRecent', 40);
    dashboardReturns($jobs, 'countSilenced', 1);

    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturns($metrics, 'jobsProcessedPerMinute', 0);
    dashboardReturns($metrics, 'throughput', 0);
    dashboardReturns($metrics, 'measuredJobs', []);
    dashboardReturns($metrics, 'measuredQueues', ['default']);
    dashboardReturns($metrics, 'runtimeForQueue', 0);

    $supervisors = mockDashboardContract(SupervisorRepository::class);
    dashboardReturns($supervisors, 'all', [
        (object) [
            'name' => 'horizon-web-01:supervisor-1',
            'master' => 'horizon-web-01',
            'status' => 'running',
            'processes' => ['redis:default' => 2],
            'options' => ['connection' => 'redis', 'balance' => 'simple'],
        ],
    ]);

    $masters = mockDashboardContract(MasterSupervisorRepository::class);
    dashboardReturns($masters, 'all', [(object) ['name' => 'horizon-web-01', 'status' => 'running']]);

    $waitTimes = mockDashboardContract(WaitTimeCalculator::class);
    dashboardReturns($waitTimes, 'calculate', ['redis:default' => 1]);

    $queue = mockDashboardContract(Queue::class);
    dashboardReturns($queue, 'reservedSize', 0);
    dashboardReturns($queue, 'readyNow', 5);
    dashboardReturns($queue, 'delayedSize', 0);
    $queues = mockDashboardContract(QueueFactory::class);
    dashboardReturns($queues, 'connection', $queue);

    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        app('db')->connection(),
        'job_batches',
    );
    $capability = new DatabaseBatchCapability($repository);
    app()->instance(BatchRepository::class, $repository);
    app()->instance(DatabaseBatchCapability::class, $capability);

    $connection = mockDashboardContract(Connection::class);
    dashboardReturns($connection, 'zcount', 0);
    dashboardReturns($connection, 'zrange', []);
    $redis = mockDashboardContract(RedisFactory::class);
    dashboardReturns($redis, 'connection', $connection);

    $pendingState = new DashboardPendingState($queues);

    app()->instance(DashboardData::class, new DashboardData(
        $jobs,
        $metrics,
        $supervisors,
        $masters,
        $queues,
        $waitTimes,
        new QueuePauseStatus(app('queue'), new QueuePauseMetadata(app('cache'))),
        $pendingState,
        new DashboardBatchSummary(
            new BatchRepositoryOverview($repository, app(CacheFactory::class)),
            $capability,
        ),
        $redis,
        app(QueueWaitThreshold::class),
        new SnapshotJobsPerMinute($redis),
    ));
    app()->instance(HorizonRuntime::class, new HorizonRuntime($masters, null, $pendingState, $waitTimes));

    get('/horizon')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Dashboard')
            ->where('summary.available', true)
            ->where('summary.pendingJobs', 5)
            ->where('summary.failedJobs', 3)
            ->where('summary.completedJobs', 36)
            ->where('summary.batchesAvailable', false)
            ->where('summary.activeBatches', null)
            ->where('summary.batchPreviews', []));
});
