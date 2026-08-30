<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Assets\AssetManifest;
use DevactionLabs\HorizonNewDawn\Batches\BatchesData;
use DevactionLabs\HorizonNewDawn\Batches\BatchFilterCatalog;
use DevactionLabs\HorizonNewDawn\Batches\BatchJobsData;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchCapability;
use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\RetryBatchJob;
use DevactionLabs\HorizonNewDawn\Jobs\JobsData;
use DevactionLabs\HorizonNewDawn\Support\HorizonRuntime;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Horizon;

use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardExpects;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturns;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\HorizonNewDawn\Tests\Support\horizonBatch;
use function DevactionLabs\HorizonNewDawn\Tests\Support\horizonJob;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

/** @param 'never'|'once'|'twice'|'zeroOrMoreTimes' $times */
function bindBatchRetryAsyncBulkQueue(string $times = 'once'): void
{
    config()->set('horizon-new-dawn.bulk_operations.connection', 'operations');
    config()->set('horizon-new-dawn.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], times: $times, value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

function bindBatchRetrySyncBulkQueue(): void
{
    config()->set('horizon-new-dawn.bulk_operations.connection', 'sync');
    config()->set('horizon-new-dawn.bulk_operations.queue', null);

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['sync'], value: new SyncQueue);
    app()->instance(QueueManager::class, $manager);
}

/** @param array<int, string> $failedJobIds */
function bindBatchRetryRepository(array $failedJobIds): void
{
    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturns($batches, 'find', horizonBatch(
        'batch-1',
        failedJobs: count($failedJobIds),
        failedJobIds: $failedJobIds,
    ));
    app()->instance(BatchRepository::class, $batches);
}

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);

    $masters = mockDashboardContract(MasterSupervisorRepository::class);
    dashboardReturns($masters, 'all', [(object) ['status' => 'running']]);
    app()->instance(HorizonRuntime::class, new HorizonRuntime($masters));
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

describe('batch pages', function (): void {
    it('renders the batch index and detail through dedicated routes', function (): void {
        $batch = horizonBatch(
            'batch-1',
            name: 'Import customer records',
            totalJobs: 5,
            pendingJobs: 2,
            failedJobs: 1,
            failedJobIds: ['failed-1'],
        );
        $batches = mockDashboardContract(BatchRepository::class);
        dashboardReturnsFor($batches, 'get', [50, null], [$batch]);
        dashboardReturnsFor($batches, 'get', [49, 'batch-1'], []);
        dashboardReturnsFor($batches, 'get', [100, null], [$batch]);
        dashboardReturnsFor($batches, 'get', [100, 'batch-1'], []);
        dashboardReturnsFor($batches, 'find', ['batch-1'], $batch);
        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($jobs, 'getPending', [null], new Collection([
            batchFeatureJob(0, 'pending-1', 'batch-1', 'pending'),
        ]));
        dashboardReturnsFor($jobs, 'getCompleted', [null], new Collection([
            batchFeatureJob(1, 'completed-1', 'batch-1', 'completed'),
            batchFeatureJob(2, 'completed-2', 'batch-1', 'completed'),
            batchFeatureJob(3, 'completed-3', 'batch-1', 'completed'),
        ]));
        dashboardReturnsFor($jobs, 'getJobs', [['failed-1']], new Collection([
            batchFeatureJob(4, 'failed-1', 'batch-1', 'failed'),
        ]));
        app()->instance(BatchRepository::class, $batches);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($batches));
        app()->instance(BatchesData::class, new BatchesData(
            $batches,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));
        app()->instance(BatchFilterCatalog::class, new BatchFilterCatalog(
            $batches,
            app(BatchesData::class),
            app(CacheFactory::class),
        ));

        get('/horizon/batches')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Batches/Index')
                ->where('meta.title', 'Batches')
                ->where('meta.activeNavigation', 'batches')
                ->where('query', '')
                ->where('batchFilterCatalog.available', true)
                ->where('batchFilterCatalog.complete', true)
                ->where('batchFilterCatalog.queues', ['imports'])
                ->where('batchFilterCatalog.connections', ['redis'])
                ->where('listRevision', '[0,"batch-1"]')
                ->where('batches.data.0.id', 'batch-1')
                ->where('batches.complete', true)
                ->where('batches.data.0.progress', 60));

        get('/horizon/batches/batch-1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Batches/Show')
                ->where('meta.title', 'Import customer records')
                ->where('batch.id', 'batch-1')
                ->where('batch.jobs.pending.total', 1)
                ->where('batch.jobs.pending.rows.0.id', 'pending-1')
                ->where('batch.jobs.completed.total', 3)
                ->where('batch.jobs.completed.rows.0.id', 'completed-1')
                ->where('batch.jobs.failed.total', 1)
                ->where('batch.jobs.failed.rows.0.id', 'failed-1'));
    });

    it('returns 404 when a batch no longer exists', function (): void {
        $batches = mockDashboardContract(BatchRepository::class);
        dashboardReturnsFor($batches, 'find', ['missing'], null);
        $jobs = mockDashboardContract(JobRepository::class);
        app()->instance(BatchRepository::class, $batches);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($batches));
        app()->instance(BatchesData::class, new BatchesData(
            $batches,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));
        app()->instance(BatchFilterCatalog::class, new BatchFilterCatalog(
            $batches,
            app(BatchesData::class),
            app(CacheFactory::class),
        ));

        get('/horizon/batches/missing')->assertNotFound();
    });

    it('ignores exact query options when the batch repository cannot support them', function (): void {
        Date::setTestNow('2026-07-21 15:00:00');

        $batch = horizonBatch('batch-1');
        $batch->createdAt = Date::now()->subHours(2)->toImmutable();
        $batches = mockDashboardContract(BatchRepository::class);
        dashboardReturnsFor($batches, 'get', [50, null], [$batch]);
        dashboardReturnsFor($batches, 'get', [49, 'batch-1'], []);
        $jobs = mockDashboardContract(JobRepository::class);
        app()->instance(BatchRepository::class, $batches);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($batches));
        app()->instance(BatchesData::class, new BatchesData(
            $batches,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));
        app()->instance(BatchFilterCatalog::class, new BatchFilterCatalog(
            $batches,
            app(BatchesData::class),
            app(CacheFactory::class),
        ));

        get('/horizon/batches?query=batch-1&queue=imports&connection=redis&created=day&status=finished&sort=name&direction=asc')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('query', '')
                ->where('filters.queue', null)
                ->where('filters.connection', null)
                ->where('filters.created', null)
                ->where('filters.status', 'all')
                ->where('filters.sort', 'createdAt')
                ->where('filters.direction', 'desc')
                ->where('batches.data.0.id', 'batch-1')
                ->where('batches.data.0.queue', 'imports')
                ->where('batches.data.0.connection', 'redis'));

        Date::setTestNow();
    });

    it('defaults database batch queries to pending progress while preserving explicit sorts', function (): void {
        config()->set('queue.batching.database', null);
        config()->set('queue.batching.table', 'job_batches');
        Schema::dropIfExists('horizon_new_dawn_batch_metadata');
        Schema::dropIfExists('job_batches');
        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        try {
            $repository = new DatabaseBatchRepository(
                app(BatchFactory::class),
                app('db')->connection(),
                'job_batches',
            );

            app()->instance(BatchRepository::class, $repository);
            app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($repository));
            app()->instance(JobRepository::class, mockDashboardContract(JobRepository::class));

            foreach ([
                '/horizon/batches' => ['pending', 'progress', 'desc'],
                '/horizon/batches?status=all' => ['pending', 'progress', 'desc'],
                '/horizon/batches?status=finished' => ['finished', 'createdAt', 'desc'],
                '/horizon/batches?status=pending&sort=name&direction=asc' => [
                    'pending',
                    'name',
                    'asc',
                ],
            ] as $url => [$status, $sort, $direction]) {
                get($url)
                    ->assertOk()
                    ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                        ->where('filters.status', $status)
                        ->where('filters.sort', $sort)
                        ->where('filters.direction', $direction));
            }
        } finally {
            Schema::dropIfExists('horizon_new_dawn_batch_metadata');
            Schema::dropIfExists('job_batches');
        }
    });

    it('queries matching database batches beyond the first 50 before pagination', function (): void {
        config()->set('queue.batching.database', null);
        config()->set('queue.batching.table', 'job_batches');
        Schema::dropIfExists('horizon_new_dawn_batch_metadata');
        Schema::dropIfExists('job_batches');
        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        $migration = require __DIR__.'/../../database/migrations/2026_07_26_000000_create_horizon_new_dawn_batch_metadata_table.php';
        $migration->up();

        try {
            foreach (range(1, 101) as $index) {
                app('db')->table('job_batches')->insert([
                    'id' => sprintf('batch-%03d', $index),
                    'name' => $index === 26 ? 'Only full-set match' : sprintf('Batch %03d', $index),
                    'total_jobs' => 10,
                    'pending_jobs' => 5,
                    'failed_jobs' => 0,
                    'failed_job_ids' => '[]',
                    'options' => serialize($index === 26
                        ? ['queue' => 'priority', 'connection' => 'redis']
                        : []),
                    'cancelled_at' => null,
                    'created_at' => 1_784_281_000 + $index,
                    'finished_at' => null,
                ]);
            }

            $repository = new DatabaseBatchRepository(
                app(BatchFactory::class),
                app('db')->connection(),
                'job_batches',
            );
            $jobs = mockDashboardContract(JobRepository::class);

            app()->instance(BatchRepository::class, $repository);
            app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($repository));
            app()->instance(JobRepository::class, $jobs);

            get('/horizon/batches?query=full-set&queue=priority&connection=redis&status=pending&sort=name&direction=asc')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                    ->where('query', 'full-set')
                    ->where('filters.queue', 'priority')
                    ->where('filters.connection', 'redis')
                    ->where('filters.status', 'pending')
                    ->where('filters.sort', 'name')
                    ->where('filters.direction', 'asc')
                    ->where('batchQueryCapability.supported', true)
                    ->where('batchFilterCatalog.queues', ['default', 'priority'])
                    ->where('batchFilterCatalog.connections', ['redis', 'sync'])
                    ->where('batchStatusCounts.all', 1)
                    ->where('batchStatusCounts.pending', 1)
                    ->where('batches.data.0.id', 'batch-026')
                    ->where('batches.data.0.queueExplicit', true)
                    ->where('batches.data.0.connectionExplicit', true));
        } finally {
            Schema::dropIfExists('horizon_new_dawn_batch_metadata');
            Schema::dropIfExists('job_batches');
        }
    });

    it('renders a deterministic storage empty state when the database batch table is missing', function (): void {
        config()->set('queue.batching.database', null);
        config()->set('queue.batching.table', 'job_batches');
        Schema::dropIfExists('horizon_new_dawn_batch_metadata');
        Schema::dropIfExists('job_batches');

        $repository = new DatabaseBatchRepository(
            app(BatchFactory::class),
            app('db')->connection(),
            'job_batches',
        );
        $jobs = mockDashboardContract(JobRepository::class);
        dashboardNeverReceives($jobs, 'getPending');

        app()->instance(BatchRepository::class, $repository);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($repository));
        app()->instance(BatchesData::class, new BatchesData(
            $repository,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));
        app()->instance(BatchFilterCatalog::class, new BatchFilterCatalog(
            $repository,
            app(BatchesData::class),
            app(CacheFactory::class),
        ));

        Exceptions::fake();

        get('/horizon/batches')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Batches/Index')
                ->where('batchesAvailable', false)
                ->where('batches.available', false)
                ->where('batches.data', [])
                ->where('batches.complete', true)
                ->where('batchQueryCapability.supported', false)
                ->where('query', '')
                ->where('listRevision', '[]')
                ->where('batches.message', fn (mixed $message): bool => is_string($message)
                    && str_contains($message, 'php artisan make:queue-batches-table')
                    && str_contains($message, 'php artisan migrate')));

        Exceptions::assertNothingReported();
    });

    it('keeps generic repository batch listing available without a relational batch table', function (): void {
        Schema::dropIfExists('horizon_new_dawn_batch_metadata');
        Schema::dropIfExists('job_batches');

        $batch = horizonBatch('batch-1', name: 'Custom repository batch');
        $batches = mockDashboardContract(BatchRepository::class);
        dashboardReturnsFor($batches, 'get', [50, null], [$batch]);
        dashboardReturnsFor($batches, 'get', [49, 'batch-1'], []);
        $jobs = mockDashboardContract(JobRepository::class);

        app()->instance(BatchRepository::class, $batches);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($batches));
        app()->instance(BatchesData::class, new BatchesData(
            $batches,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));
        app()->instance(BatchFilterCatalog::class, new BatchFilterCatalog(
            $batches,
            app(BatchesData::class),
            app(CacheFactory::class),
        ));

        get('/horizon/batches')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('batchesAvailable', true)
                ->where('batches.available', true)
                ->where('batches.data.0.id', 'batch-1')
                ->where('batchQueryCapability.supported', false));
    });

    it('rejects unsupported batch created ranges', function (): void {
        get('/horizon/batches?created=forever')
            ->assertRedirect()
            ->assertSessionHasErrors('created');
    });

    it('rejects unsupported batch statuses and sort options', function (): void {
        get('/horizon/batches?status=unknown&sort=runtime&direction=sideways')
            ->assertRedirect()
            ->assertSessionHasErrors(['status', 'sort', 'direction']);

        get('/horizon/batches?sort=queueActivity')
            ->assertRedirect()
            ->assertSessionHasErrors('sort');
    });

    it('skips unrequested batch pages and resolves shared page props once', function (): void {
        $batches = mockDashboardContract(BatchRepository::class);
        dashboardNeverReceives($batches, 'get');
        $jobs = mockDashboardContract(JobRepository::class);

        app()->instance(BatchRepository::class, $batches);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($batches));
        app()->instance(JobRepository::class, $jobs);
        app()->instance(BatchesData::class, new BatchesData(
            $batches,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));

        getJson('/horizon/batches', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Batches/Index',
            'X-Inertia-Partial-Data' => 'filters',
        ])
            ->assertOk()
            ->assertJsonStructure(['props' => ['filters']])
            ->assertJsonMissingPath('props.batches');

        $pageBatches = mockDashboardContract(BatchRepository::class);
        dashboardReturnsFor($pageBatches, 'get', [50, null], []);

        app()->instance(BatchRepository::class, $pageBatches);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($pageBatches));
        app()->instance(BatchesData::class, new BatchesData(
            $pageBatches,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));

        getJson('/horizon/batches', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Batches/Index',
            'X-Inertia-Partial-Data' => 'batchStatusCounts,batches',
        ])
            ->assertOk()
            ->assertJsonStructure(['props' => ['batchStatusCounts', 'batches']]);
    });

    it('does not resolve the batch page while loading its filter catalog', function (): void {
        config()->set('horizon-new-dawn.poll_interval', 0);

        $batches = mockDashboardContract(BatchRepository::class);
        dashboardExpects($batches, 'get', [50, null], times: 'never');
        dashboardReturnsFor($batches, 'get', [100, null], []);
        $jobs = mockDashboardContract(JobRepository::class);

        app()->instance(BatchRepository::class, $batches);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($batches));
        app()->instance(JobRepository::class, $jobs);
        app()->instance(BatchesData::class, new BatchesData(
            $batches,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));
        app()->instance(BatchFilterCatalog::class, new BatchFilterCatalog(
            $batches,
            app(BatchesData::class),
            app(CacheFactory::class),
        ));

        getJson('/horizon/batches', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Batches/Index',
            'X-Inertia-Partial-Data' => 'batchFilterCatalog',
        ])
            ->assertOk()
            ->assertJsonPath('props.batchFilterCatalog.available', true)
            ->assertJsonMissingPath('props.batches');
    });

    it('does not evaluate retained-history summaries during ordinary list partial reloads', function (): void {
        $batches = mockDashboardContract(BatchRepository::class);
        dashboardReturnsFor($batches, 'get', [50, null], []);
        $jobs = mockDashboardContract(JobRepository::class);

        app()->instance(BatchRepository::class, $batches);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($batches));
        app()->instance(JobRepository::class, $jobs);
        app()->instance(BatchesData::class, new BatchesData(
            $batches,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));
        app()->instance(BatchFilterCatalog::class, new BatchFilterCatalog(
            $batches,
            app(BatchesData::class),
            app(CacheFactory::class),
        ));

        getJson('/horizon/batches', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Batches/Index',
            'X-Inertia-Partial-Data' => 'batches',
            'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
        ])
            ->assertOk()
            ->assertJsonPath('component', 'Batches/Index')
            ->assertJsonStructure(['props' => ['batches']])
            ->assertJsonPath('prependProps.0', 'batches.data')
            ->assertJsonPath('matchPropsOn.0', 'batches.data.id')
            ->assertJsonMissingPath('props.listRevision')
            ->assertJsonMissingPath('props.batchClearCounts')
            ->assertJsonMissingPath('props.batchFilterCatalog');
    });

    it('returns the batch list revision without returning the infinite-scroll prop', function (): void {
        $batch = horizonBatch('latest-batch');
        $batches = mockDashboardContract(BatchRepository::class);
        dashboardReturnsFor($batches, 'get', [50, null], [$batch]);
        dashboardReturnsFor($batches, 'get', [49, 'latest-batch'], []);
        $jobs = mockDashboardContract(JobRepository::class);

        app()->instance(BatchRepository::class, $batches);
        app()->instance(DatabaseBatchCapability::class, new DatabaseBatchCapability($batches));
        app()->instance(BatchesData::class, new BatchesData(
            $batches,
            new BatchJobsData($jobs, new JobsData($jobs)),
        ));

        getJson('/horizon/batches', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Batches/Index',
            'X-Inertia-Partial-Data' => 'listRevision',
        ])
            ->assertOk()
            ->assertJsonPath('props.listRevision', '[0,"latest-batch"]')
            ->assertJsonMissingPath('props.batches')
            ->assertJsonMissingPath('scrollProps.batches')
            ->assertJsonMissingPath('prependProps')
            ->assertJsonMissingPath('matchPropsOn');
    });

    it('queues retrying failed batch jobs and honors Horizon authorization', function (): void {
        Bus::fake();
        bindBatchRetryAsyncBulkQueue();
        bindBatchRetryRepository(['failed-1']);

        post('/horizon/batches/batch-1/retry')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Retrying failed jobs for batch batch-1 was queued.');

        Bus::assertDispatched(
            RetryBatchJob::class,
            fn (RetryBatchJob $job): bool => $job->batchId === 'batch-1'
                && $job->connection === 'operations'
                && $job->queue === 'horizon-maintenance',
        );
        Bus::assertDispatchedTimes(RetryBatchJob::class, 1);

        Horizon::auth(static fn (): bool => false);
        post('/horizon/batches/batch-1/retry')->assertForbidden();
    });

    it('reports when a batch retry cannot use an asynchronous bulk queue', function (): void {
        Bus::fake();
        Exceptions::fake();
        bindBatchRetrySyncBulkQueue();
        bindBatchRetryRepository(['failed-1']);

        post('/horizon/batches/batch-1/retry')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );

        Bus::assertNothingDispatched();
        Exceptions::assertReportedCount(1);
    });

    it('queues retrying a batch when its failed jobs exceed the former ceiling', function (): void {
        Bus::fake();
        bindBatchRetryAsyncBulkQueue();
        bindBatchRetryRepository(['failed-1', 'failed-2']);

        post('/horizon/batches/batch-1/retry')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.success',
                'Retrying failed jobs for batch batch-1 was queued.',
            );

        Bus::assertDispatched(RetryBatchJob::class);
    });
});

function batchFeatureJob(int $index, string $id, string $batchId, string $status): object
{
    $job = horizonJob($index, $id);
    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['batchId'] = $batchId;
    $job->payload = json_encode($payload, JSON_THROW_ON_ERROR);
    $job->status = $status;

    return $job;
}
