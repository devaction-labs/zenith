<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith;

use DevactionLabs\Zenith\Assets\AssetPath;
use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\Batches\DatabaseBatchMetadataSynchronizer;
use DevactionLabs\Zenith\Batches\DatabaseBatchQuery;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\Zenith\Chunks\ChunkBuffer;
use DevactionLabs\Zenith\Console\AssetsCommand;
use DevactionLabs\Zenith\Console\InstallCommand;
use DevactionLabs\Zenith\Console\WarmBatchMetadataCommand;
use DevactionLabs\Zenith\Console\WarmRetainedJobsCommand;
use DevactionLabs\Zenith\Dashboard\DashboardPendingState;
use DevactionLabs\Zenith\FailedJobs\Actions\RetryAllFailedJobs;
use DevactionLabs\Zenith\FailedJobs\Actions\RetryFailedJob;
use DevactionLabs\Zenith\FailedJobs\FailedJobRetryEligibility;
use DevactionLabs\Zenith\FailedJobs\FailedJobRetryLock;
use DevactionLabs\Zenith\Http\Controllers\BatchesApiController;
use DevactionLabs\Zenith\Http\Controllers\HomeController;
use DevactionLabs\Zenith\Http\Controllers\MonitoringApiController;
use DevactionLabs\Zenith\Http\Middleware\HandleInertiaRequests;
use DevactionLabs\Zenith\Http\Middleware\RecordHorizonMutation;
use DevactionLabs\Zenith\Jobs\Actions\CancelPendingJob;
use DevactionLabs\Zenith\Jobs\Actions\CancelPendingJobs;
use DevactionLabs\Zenith\Jobs\ForgetsPendingJob;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Jobs\PendingJobEntryScanner;
use DevactionLabs\Zenith\Jobs\PendingJobStateIndex;
use DevactionLabs\Zenith\Jobs\RetainedJobFilterCatalog;
use DevactionLabs\Zenith\Jobs\RetainedJobIndex;
use DevactionLabs\Zenith\Jobs\RetainedJobQuery;
use DevactionLabs\Zenith\Jobs\RetainedJobRetryEligibility;
use DevactionLabs\Zenith\Queues\ClearQueueMetadata;
use DevactionLabs\Zenith\Queues\ClearsQueueMetadata;
use DevactionLabs\Zenith\Queues\RecordQueueFailover;
use DevactionLabs\Zenith\Schedule\DynamicSchedule;
use DevactionLabs\Zenith\Schedule\InternalScheduledEvent;
use DevactionLabs\Zenith\Support\FrameworkCapabilities;
use DevactionLabs\Zenith\Support\HorizonRuntime;
use DevactionLabs\Zenith\Support\HorizonWorkCommandCompatibility;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Events\QueueFailedOver;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Inertia\Ssr\ExcludesSsrPaths;
use Inertia\Ssr\Gateway;
use Laravel\Horizon\Console\WorkCommand as HorizonWorkCommand;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\Http\Controllers\BatchesController as HorizonBatchesController;
use Laravel\Horizon\Http\Controllers\HomeController as HorizonHomeController;
use Laravel\Horizon\Http\Controllers\MonitoringController as HorizonMonitoringController;
use Laravel\Horizon\Http\Middleware\Authenticate;
use Laravel\Horizon\WaitTimeCalculator;

final class ZenithServiceProvider extends ServiceProvider
{
    /** @var list<string> */
    private const array ROOT_PATH_SSR_EXCLUSIONS = [
        '/',
        'dashboard',
        'instances',
        'supervisors/*',
        'monitoring',
        'monitoring/*',
        'metrics',
        'metrics/jobs',
        'metrics/jobs/*',
        'metrics/queues',
        'metrics/queues/*',
        'batches',
        'batches/*',
        'queues',
        'queues/*',
        'jobs/pending',
        'jobs/pending/*',
        'jobs/completed',
        'jobs/completed/*',
        'jobs/silenced',
        'jobs/silenced/*',
        'failed',
        'failed/*',
        'audit',
        'audit/*',
        'schedule',
        'schedule/*',
        'workflows',
        'workflows/*',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/zenith.php',
            'zenith',
        );

        $this->app->bind(HorizonBatchesController::class, BatchesApiController::class);
        $this->app->bind(HorizonHomeController::class, HomeController::class);
        $this->app->bind(HorizonMonitoringController::class, MonitoringApiController::class);
        $this->app->bind(ClearsQueueMetadata::class, ClearQueueMetadata::class);
        $this->app->bind(ForgetsPendingJob::class, ClearQueueMetadata::class);
        $this->app->scoped(DatabaseBatchCapability::class);
        $this->app->scoped(DatabaseBatchMetadataSynchronizer::class);
        $this->app->scoped(DatabaseBatchQuery::class);
        $this->app->scoped(PendingJobStateIndex::class);
        $this->app->bind(
            PendingJobEntryScanner::class,
            fn (): PendingJobEntryScanner => $this->app->make(PendingJobStateIndex::class),
        );
        $this->app->scoped(RetainedJobIndex::class);
        $this->app->scoped(RetainedJobQuery::class);
        $this->app->scoped(
            RetainedJobFilterCatalog::class,
            fn (): RetainedJobFilterCatalog => new RetainedJobFilterCatalog(
                index: $this->app->make(RetainedJobIndex::class),
                cache: $this->app->make(CacheFactory::class),
            ),
        );
        $this->app->bind(
            RetryFailedJob::class,
            fn (): RetryFailedJob => new RetryFailedJob(
                bus: $this->app->make(Dispatcher::class),
                jobs: $this->app->make(JobRepository::class),
                eligibility: $this->app->make(FailedJobRetryEligibility::class),
                lock: $this->app->make(FailedJobRetryLock::class),
            ),
        );
        $this->app->bind(
            CancelPendingJobs::class,
            fn (): CancelPendingJobs => new CancelPendingJobs(
                jobs: $this->app->make(JobRepository::class),
                cancel: $this->app->make(CancelPendingJob::class),
                snapshots: $this->app->make(BulkOperationSnapshot::class),
            ),
        );
        $this->app->bind(
            RetryAllFailedJobs::class,
            fn (): RetryAllFailedJobs => new RetryAllFailedJobs(
                jobs: $this->app->make(JobRepository::class),
                retry: $this->app->make(RetryFailedJob::class),
                snapshots: $this->app->make(BulkOperationSnapshot::class),
            ),
        );
        $this->app->bind(
            JobsData::class,
            fn (): JobsData => new JobsData(
                jobs: $this->app->make(JobRepository::class),
                redis: $this->app->make(RedisFactory::class),
                retainedQuery: $this->app->make(RetainedJobQuery::class),
                filterCatalog: $this->app->make(RetainedJobFilterCatalog::class),
                retryEligibility: $this->app->make(RetainedJobRetryEligibility::class),
            ),
        );
        $this->app->resolving(
            HorizonWorkCommand::class,
            function (HorizonWorkCommand $command): void {
                $this->app
                    ->make(HorizonWorkCommandCompatibility::class)
                    ->prepare($command);
            },
        );
        $this->app->singleton(
            FrameworkCapabilities::class,
            fn (): FrameworkCapabilities => FrameworkCapabilities::detect(),
        );
        $this->app->bind(
            HorizonRuntime::class,
            fn (): HorizonRuntime => new HorizonRuntime(
                $this->app->make(MasterSupervisorRepository::class),
                $this->app->make(WorkloadRepository::class),
                $this->app->make(DashboardPendingState::class),
                $this->app->make(WaitTimeCalculator::class),
            ),
        );
        $this->app->booting(fn () => $this->registerRoutes());
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(AssetPath $assetPath): void
    {
        $this->app->make(EventDispatcher::class)->listen(QueueFailedOver::class, RecordQueueFailover::class);

        $this->excludeHorizonFromSsr($this->app->make(Gateway::class));

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'zenith');

        $this->publishes([
            __DIR__.'/../config/zenith.php' => config_path('zenith.php'),
        ], 'zenith-config');

        $this->publishes([
            __DIR__.'/../dist/build' => $assetPath->absolute(),
        ], 'zenith-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AssetsCommand::class,
                InstallCommand::class,
                WarmBatchMetadataCommand::class,
                WarmRetainedJobsCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, $this->registerScheduledEvents(...));
    }

    private function registerScheduledEvents(Schedule $schedule): void
    {
        $schedule->call(static fn (DynamicSchedule $crons): int => $crons->tick())
            ->everyMinute()
            ->name(InternalScheduledEvent::DynamicCrons->value)
            ->withoutOverlapping(10);

        $schedule->call(static function (ChunkBuffer $chunks): void {
            $chunks->flushDue();
        })
            ->everyMinute()
            ->name(InternalScheduledEvent::ChunkFlush->value);
    }

    private function excludeHorizonFromSsr(Gateway $gateway): void
    {
        if (! $gateway instanceof ExcludesSsrPaths) {
            return;
        }

        $configuredPath = config('horizon.path', 'horizon');

        if (! is_string($configuredPath)) {
            return;
        }

        $path = trim($configuredPath, '/');

        Inertia::withoutSsr(
            $path === ''
                ? self::ROOT_PATH_SSR_EXCLUSIONS
                : [$path, "{$path}/*"],
        );
    }

    /**
     * @throws BindingResolutionException
     */
    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $this->app->make(Router::class)->group([
            'domain' => config('horizon.domain'),
            'prefix' => config('horizon.path'),
            'middleware' => [
                'horizon',
                Authenticate::class,
                HandleInertiaRequests::class,
                RecordHorizonMutation::class,
            ],
            'as' => 'zenith.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/zenith.php');
        });
    }
}
