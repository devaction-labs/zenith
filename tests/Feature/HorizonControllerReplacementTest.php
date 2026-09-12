<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Assets\AssetManifest;
use DevactionLabs\Zenith\Batches\BatchRepositoryOverview;
use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryBatchJob;
use DevactionLabs\Zenith\Dashboard\DashboardBatchSummary;
use DevactionLabs\Zenith\Dashboard\DashboardData;
use DevactionLabs\Zenith\Dashboard\DashboardPendingState;
use DevactionLabs\Zenith\Http\Controllers\BatchesApiController;
use DevactionLabs\Zenith\Http\Controllers\HomeController;
use DevactionLabs\Zenith\Http\Controllers\MonitoringApiController;
use DevactionLabs\Zenith\Http\Middleware\HandleInertiaRequests;
use DevactionLabs\Zenith\Metrics\SnapshotJobsPerMinute;
use DevactionLabs\Zenith\Queues\QueuePauseMetadata;
use DevactionLabs\Zenith\Queues\QueuePauseStatus;
use DevactionLabs\Zenith\Queues\QueueWaitThreshold;
use DevactionLabs\Zenith\Support\FrameworkCapabilities;
use DevactionLabs\Zenith\Support\HorizonRuntime;
use DevactionLabs\Zenith\Tests\TestCase;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Http\Controllers\BatchesController as HorizonBatchesController;
use Laravel\Horizon\Http\Controllers\HomeController as HorizonHomeController;
use Laravel\Horizon\Http\Controllers\MonitoringController as HorizonMonitoringController;
use Laravel\Horizon\Http\Controllers\RetryController as HorizonRetryController;
use Laravel\Horizon\Http\Middleware\Authenticate;
use Laravel\Horizon\Jobs\MonitorTag as HorizonMonitorTag;
use Laravel\Horizon\Jobs\RetryFailedJob as HorizonRetryFailedJob;
use Laravel\Horizon\Jobs\StopMonitoringTag as HorizonStopMonitoringTag;
use Laravel\Horizon\WaitTimeCalculator;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutMiddleware;

describe('Horizon controller replacement', function (): void {
    it('keeps the Horizon catch-all as an authenticated fallback', function (): void {
        expect(app(HorizonHomeController::class))->toBeInstanceOf(HomeController::class);

        $route = Route::getRoutes()->getByName('horizon.index');

        expect($route)->not->toBeNull()
            ->and($route?->gatherMiddleware())->not->toContain(HandleInertiaRequests::class)
            ->and($route?->gatherMiddleware())->toContain(Authenticate::class);
    });

    it('preserves monitoring input safeguards on the Horizon API', function (): void {
        withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
        Horizon::auth(static fn (): bool => true);
        Bus::fake();

        $jobs = mockDashboardContract(JobRepository::class);
        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($tags, 'monitoring', ['checkout']);
        app()->instance(JobRepository::class, $jobs);
        app()->instance(TagRepository::class, $tags);

        expect(app(HorizonMonitoringController::class))
            ->toBeInstanceOf(MonitoringApiController::class);

        postJson('/horizon/api/monitoring', ['tag' => 'pending_jobs'])
            ->assertUnprocessable();
        deleteJson('/horizon/api/monitoring/pending_jobs')
            ->assertUnprocessable();
        deleteJson('/horizon/api/monitoring/not-monitored')
            ->assertUnprocessable();

        postJson('/horizon/api/monitoring', ['tag' => 'checkout'])
            ->assertOk();
        deleteJson('/horizon/api/monitoring/checkout')
            ->assertOk();

        Bus::assertDispatched(
            HorizonMonitorTag::class,
            fn (HorizonMonitorTag $job): bool => $job->tag === 'checkout',
        );
        Bus::assertDispatched(
            HorizonStopMonitoringTag::class,
            fn (HorizonStopMonitoringTag $job): bool => $job->tag === 'checkout',
        );
        Bus::assertDispatchedTimes(HorizonMonitorTag::class, 1);
        Bus::assertDispatchedTimes(HorizonStopMonitoringTag::class, 1);
    });

    it('safely queues the preserved Horizon retry APIs', function (): void {
        withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
        Horizon::auth(static fn (): bool => true);
        Bus::fake();
        config()->set('zenith.bulk_operations.connection', 'operations');
        config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

        $manager = Mockery::mock(QueueManager::class);
        dashboardExpects($manager, 'connection', ['operations'], value: Mockery::mock(Queue::class));
        app()->instance(QueueManager::class, $manager);

        $retryController = app(HorizonRetryController::class);

        expect(app(HorizonBatchesController::class))
            ->toBeInstanceOf(BatchesApiController::class);
        expect($retryController::class)->toBe(HorizonRetryController::class);

        postJson('/horizon/api/batches/retry/batch-1')->assertOk();
        postJson('/horizon/api/jobs/retry/job-1')->assertOk();

        Bus::assertDispatched(
            RetryBatchJob::class,
            fn (RetryBatchJob $job): bool => $job->batchId === 'batch-1'
                && $job->connection === 'operations'
                && $job->queue === 'horizon-maintenance',
        );
        Bus::assertDispatched(
            HorizonRetryFailedJob::class,
            fn (HorizonRetryFailedJob $job): bool => $job->id === 'job-1',
        );
        Bus::assertDispatchedTimes(RetryBatchJob::class, 1);
        Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, 1);
    });

    it('returns the Zenith dashboard from the concrete package route', function (): void {
        config()->set('zenith', []);

        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturns($jobs, 'countFailed', 3);
        dashboardReturns($jobs, 'countCompleted', 36);
        dashboardReturns($jobs, 'countPending', 5);
        dashboardReturns($jobs, 'countRecentlyFailed', 2);
        dashboardReturns($jobs, 'countRecent', 40);
        dashboardReturns($jobs, 'countSilenced', 1);

        $metrics = mockDashboardContract(MetricsRepository::class);
        dashboardReturns($metrics, 'jobsProcessedPerMinute', 12);
        dashboardReturns($metrics, 'throughput', 40);
        dashboardReturns($metrics, 'throughputForQueue', 0);
        dashboardReturns($metrics, 'measuredQueues', ['default']);

        $supervisors = mockDashboardContract(SupervisorRepository::class);
        dashboardReturns($supervisors, 'all', [
            (object) [
                'name' => 'horizon-web-01:supervisor-1',
                'master' => 'horizon-web-01',
                'status' => 'running',
                'processes' => ['redis:default' => 3],
                'options' => ['connection' => 'redis', 'balance' => 'auto'],
            ],
        ]);

        $masters = mockDashboardContract(MasterSupervisorRepository::class);
        dashboardReturns($masters, 'all', [(object) ['name' => 'horizon-web-01', 'status' => 'running']]);

        $workload = mockDashboardContract(WorkloadRepository::class);
        dashboardReturns($workload, 'get', [
            ['name' => 'default', 'length' => 8, 'wait' => 4, 'processes' => 3, 'split_queues' => null],
        ]);

        $waitTimes = mockDashboardContract(WaitTimeCalculator::class);
        dashboardReturns($waitTimes, 'calculate', ['redis:default' => 4]);

        $queue = mockDashboardContract(Queue::class);
        dashboardReturns($queue, 'reservedSize', 1);
        dashboardReturns($queue, 'readyNow', 8);
        dashboardReturns($queue, 'delayedSize', 0);
        $queues = mockDashboardContract(QueueFactory::class);
        dashboardReturns($queues, 'connection', $queue);

        $batches = mockDashboardContract(BatchRepository::class);
        dashboardReturnsFor($batches, 'get', [100, null], []);

        $connection = mockDashboardContract(Connection::class);
        dashboardReturns($connection, 'zcount', 0);
        dashboardReturnsFor(
            $connection,
            'zrange',
            ['snapshot:queue:default', -1, -1],
            [json_encode(['runtime' => 1_100, 'throughput' => 80], JSON_THROW_ON_ERROR)],
        );
        $redis = mockDashboardContract(RedisFactory::class);
        dashboardReturns($redis, 'connection', $connection);
        $pendingState = new DashboardPendingState($queues);
        $queueManager = app(QueueManager::class);

        if (queuePausingIsSupported()) {
            $queueManager->resume('redis', 'default');
        }

        app()->instance(DashboardData::class, new DashboardData(
            $jobs,
            $metrics,
            $supervisors,
            $masters,
            $queues,
            $waitTimes,
            new QueuePauseStatus($queueManager, new QueuePauseMetadata(app('cache'))),
            $pendingState,
            new DashboardBatchSummary(
                new BatchRepositoryOverview(
                    $batches,
                    app(CacheFactory::class),
                ),
                new DatabaseBatchCapability($batches),
            ),
            $redis,
            app(QueueWaitThreshold::class),
            new SnapshotJobsPerMinute($redis),
        ));
        app()->instance(
            HorizonRuntime::class,
            new HorizonRuntime($masters, $workload, $pendingState, $waitTimes),
        );

        get('/horizon')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Dashboard')
                ->where('meta.title', 'Dashboard')
                ->where('meta.activeNavigation', 'dashboard')
                ->where('horizon.baseUrl', url('/horizon'))
                ->where('horizon.pollInterval', 5000)
                ->where('horizon.status', 'running')
                ->where('horizon.processing', true)
                ->where('horizon.maintenanceMode', false)
                ->where('horizon.jobNavigationBreakdown', false)
                ->where('summary.available', true)
                ->where('summary.status', 'running')
                ->where('summary.pendingJobs', 5)
                ->where('summary.failedJobs', 3)
                ->where('summary.completedJobs', 36)
                ->where('workload.available', true)
                ->where('workload.items.0.name', 'default')
                ->where('supervisors.available', true)
                ->where('supervisors.groups.0.name', 'horizon-web-01')
                ->missing('recentFailures')
                ->missing('recent_failures')
                ->missing('failures'));

        get('/horizon/instances')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Instances/Index')
                ->where('meta.title', 'Instances')
                ->where('meta.activeNavigation', 'instances')
                ->where('supervisors.available', true)
                ->where('supervisors.groups.0.name', 'horizon-web-01'));
    });

    it('does not claim unsupported Horizon screens', function (): void {
        get('/horizon/jobs')->assertNotFound();
    });

    it('shares unavailable framework capabilities with the interface', function (): void {
        app()->instance(
            FrameworkCapabilities::class,
            new FrameworkCapabilities(queuePausing: false, timedQueuePausing: false),
        );

        get('/horizon')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('horizon.capabilities.queuePausing', false)
                ->where('horizon.capabilities.timedQueuePausing', false)
                ->where('horizon.capabilities.queuePausingAll', false));
    });

    it('shares timed queue pausing separately from basic queue pausing', function (): void {
        app()->instance(
            FrameworkCapabilities::class,
            new FrameworkCapabilities(queuePausing: true, timedQueuePausing: false),
        );

        get('/horizon')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('horizon.capabilities.queuePausing', true)
                ->where('horizon.capabilities.timedQueuePausing', false)
                ->where('horizon.capabilities.queuePausingAll', false));
    });

    it('shares Laravel global queue pause state with the interface', function (): void {
        requireQueuePausingAll();

        app(QueueManager::class)->pauseAll();

        get('/horizon')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('horizon.allQueuesPaused', true));
    });

    it('shares the scheduler pause state with the interface', function (): void {
        Artisan::call('schedule:pause');

        get('/horizon')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('horizon.schedulePaused', true));

        Artisan::call('schedule:resume');

        get('/horizon')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('horizon.schedulePaused', false));
    });

    it('shares the enabled job navigation breakdown with the interface', function (): void {
        config()->set('zenith.job_navigation_breakdown', true);

        get('/horizon')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('horizon.jobNavigationBreakdown', true));
    });

    it('shares the configured custom Horizon path as horizon.baseUrl', function (): void {
        /** @var TestCase $this */
        $repository = Env::getRepository();
        $previous = $repository->get('HORIZON_PATH');

        try {
            $repository->set('HORIZON_PATH', 'operations/horizon');
            $this->rebootstrapApplication();

            get('/operations/horizon', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(AssetManifest::class)->version(),
            ])
                ->assertOk()
                ->assertJsonPath('component', 'Dashboard')
                ->assertJsonPath('props.horizon.baseUrl', url('/operations/horizon'));
        } finally {
            if ($previous === null) {
                $repository->clear('HORIZON_PATH');
            } else {
                $repository->set('HORIZON_PATH', $previous);
            }

            $this->rebootstrapApplication();
        }
    });
});
