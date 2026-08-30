<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn;

use DevactionLabs\HorizonNewDawn\Assets\AssetPath;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchCapability;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchMetadataSynchronizer;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchQuery;
use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\HorizonNewDawn\Console\AssetsCommand;
use DevactionLabs\HorizonNewDawn\Console\InstallCommand;
use DevactionLabs\HorizonNewDawn\Console\WarmBatchMetadataCommand;
use DevactionLabs\HorizonNewDawn\Console\WarmRetainedJobsCommand;
use DevactionLabs\HorizonNewDawn\Dashboard\DashboardPendingState;
use DevactionLabs\HorizonNewDawn\FailedJobs\Actions\RetryAllFailedJobs;
use DevactionLabs\HorizonNewDawn\FailedJobs\Actions\RetryFailedJob;
use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobRetryEligibility;
use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobRetryLock;
use DevactionLabs\HorizonNewDawn\Http\Controllers\BatchesApiController;
use DevactionLabs\HorizonNewDawn\Http\Controllers\HomeController;
use DevactionLabs\HorizonNewDawn\Http\Controllers\MonitoringApiController;
use DevactionLabs\HorizonNewDawn\Http\Middleware\HandleInertiaRequests;
use DevactionLabs\HorizonNewDawn\Http\Middleware\RecordHorizonMutation;
use DevactionLabs\HorizonNewDawn\Jobs\Actions\CancelPendingJob;
use DevactionLabs\HorizonNewDawn\Jobs\Actions\CancelPendingJobs;
use DevactionLabs\HorizonNewDawn\Jobs\ForgetsPendingJob;
use DevactionLabs\HorizonNewDawn\Jobs\JobsData;
use DevactionLabs\HorizonNewDawn\Jobs\PendingJobEntryScanner;
use DevactionLabs\HorizonNewDawn\Jobs\PendingJobStateIndex;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobFilterCatalog;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobIndex;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobQuery;
use DevactionLabs\HorizonNewDawn\Queues\ClearQueueMetadata;
use DevactionLabs\HorizonNewDawn\Queues\ClearsQueueMetadata;
use DevactionLabs\HorizonNewDawn\Support\FrameworkCapabilities;
use DevactionLabs\HorizonNewDawn\Support\HorizonRuntime;
use DevactionLabs\HorizonNewDawn\Support\HorizonWorkCommandCompatibility;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
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

final class HorizonNewDawnServiceProvider extends ServiceProvider
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
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/horizon-new-dawn.php',
            'horizon-new-dawn',
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
        $this->excludeHorizonFromSsr($this->app->make(Gateway::class));

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'horizon-new-dawn');

        $this->publishes([
            __DIR__.'/../config/horizon-new-dawn.php' => config_path('horizon-new-dawn.php'),
        ], 'horizon-new-dawn-config');

        $this->publishes([
            __DIR__.'/../dist/build' => $assetPath->absolute(),
        ], 'horizon-new-dawn-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AssetsCommand::class,
                InstallCommand::class,
                WarmBatchMetadataCommand::class,
                WarmRetainedJobsCommand::class,
            ]);
        }
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
            'as' => 'horizon-new-dawn.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/horizon-new-dawn.php');
        });
    }
}
