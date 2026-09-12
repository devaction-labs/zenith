<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Assets\AssetManifest;
use DevactionLabs\Zenith\BulkOperations\Jobs\ClearFailedJobsJob;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryAllFailedJobsJob;
use DevactionLabs\Zenith\FailedJobs\Actions\RetryFailedJob;
use DevactionLabs\Zenith\FailedJobs\FailedJobRetryEligibility;
use DevactionLabs\Zenith\FailedJobs\FailedJobsData;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Support\HorizonRuntime;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Jobs\RetryFailedJob as HorizonRetryFailedJob;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

/**
 * Single-job retries take a Redis lock. Package tests must not require live Redis;
 * FailedJobRetryLock is final, so skip the lock via RetryFailedJob's optional null.
 * Bind lazily so JobRepository mocks registered after this helper still resolve.
 */
function bindFailedJobRetryLockWithoutRedis(): void
{
    app()->bind(
        RetryFailedJob::class,
        static fn (): RetryFailedJob => new RetryFailedJob(
            app(Dispatcher::class),
            app(JobRepository::class),
            app(FailedJobRetryEligibility::class),
            null,
        ),
    );
}

function bindFailedJobsAsyncBulkQueue(): void
{
    config()->set('zenith.bulk_operations.connection', 'operations');
    config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

function bindFailedJobsSyncBulkQueue(): void
{
    config()->set('zenith.bulk_operations.connection', 'sync');
    config()->set('zenith.bulk_operations.queue', null);

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['sync'], times: 'twice', value: new SyncQueue);
    app()->instance(QueueManager::class, $manager);
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

describe('failed job pages', function (): void {
    it('renders a safe failed list and detail', function (): void {
        $job = horizonJob(0, 'failed-1');
        $job->status = 'failed';
        $job->failed_at = '1784281003.5';
        $job->context = json_encode(['tenant' => 42], JSON_THROW_ON_ERROR);

        $repository = mockDashboardContract(JobRepository::class);
        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($repository, 'getFailed', new Collection([$job]));
        dashboardReturns($repository, 'countFailed', 1);
        dashboardReturnsFor($repository, 'findFailed', ['failed-1'], $job);
        dashboardReturnsFor($tags, 'paginate', ['failed:tenant:42', 0, 51], [0 => 'failed-1']);
        dashboardReturnsFor($tags, 'count', ['failed:tenant:42'], 1);
        dashboardReturnsFor($repository, 'getJobs', [['failed-1'], 0], new Collection([$job]));
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            $tags,
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        get('/horizon/failed')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('FailedJobs/Index')
                ->where('meta.title', 'Failed Jobs')
                ->where('meta.activeNavigation', 'failed')
                ->where('filters.job', null)
                ->where('filters.queue', null)
                ->where('filters.connection', null)
                ->where('filters.state', null)
                ->missing('sort')
                ->missing('direction')
                ->missing('filterCatalog')
                ->where('querySignature', fn (mixed $value): bool => is_string($value)
                    && strlen($value) === 64)
                ->where('actions.retryable', true)
                ->where('listRevision', '[1,"failed-1"]')
                ->missing('jobs.actions')
                ->where('jobs.data.0.id', 'failed-1')
                ->where('jobs.data.0.retried', false)
                ->where('jobs.data.0.retryEligible', true)
                ->missing('jobs.data.0.payload')
                ->missing('jobs.data.0.exception')
                ->missing('jobs.data.0.context'));

        get('/horizon/failed?tag=tenant%3A42')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('FailedJobs/Index')
                ->where('query', 'tenant:42')
                ->where('jobs.total', 1)
                ->where('jobs.data.0.id', 'failed-1'));

        get('/horizon/failed/failed-1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('FailedJobs/Show')
                ->where('job.id', 'failed-1')
                ->where('job.context.tenant', 42)
                ->where('job.payload.displayName', 'App\\Jobs\\ImportFeed')
                ->where('job.retryEligible', true)
                ->where('job.exception', 'sensitive trace'));
    });

    it('does not pass browser row sorting into the failed-job backend query', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getFailed', new Collection);
        dashboardReturns($repository, 'countFailed', 0);
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            mockDashboardContract(TagRepository::class),
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        get('/horizon/failed?sort=failedAt&direction=desc')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->missing('sort')
                ->missing('direction')
                ->where('filters.job', null)
                ->where('filters.queue', null)
                ->where('filters.connection', null)
                ->where('filters.state', null));
    });

    it('returns not found when a non-failed job id is used for failed detail', function (): void {
        $job = horizonJob(0, 'completed-1');
        $job->status = 'completed';

        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($repository, 'findFailed', ['completed-1'], $job);
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            mockDashboardContract(TagRepository::class),
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        get('/horizon/failed/completed-1')->assertNotFound();
    });

    it('keeps the failed-job filter catalog out of the navigation deferred request', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getFailed', new Collection);
        dashboardReturns($repository, 'countFailed', 0);
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            mockDashboardContract(TagRepository::class),
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        $response = getJson('/horizon/failed', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
        ])->assertOk();

        expect($response->json('deferredProps.navigation'))
            ->toContain('navigationCounts')
            ->not()->toContain('filterCatalog');
    });

    it('loads the optional failed-job filter catalog without resolving the page', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardNeverReceives($repository, 'getFailed');
        dashboardNeverReceives($repository, 'countFailed');
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            mockDashboardContract(TagRepository::class),
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        getJson('/horizon/failed', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'FailedJobs/Index',
            'X-Inertia-Partial-Data' => 'filterCatalog',
        ])
            ->assertOk()
            ->assertJsonPath('props.filterCatalog.available', false)
            ->assertJsonMissingPath('props.jobs');
    });

    it('does not recompute bulk actions for an infinite-scroll jobs request', function (): void {
        $job = horizonJob(0, 'failed-1');
        $repository = mockDashboardContract(JobRepository::class);
        dashboardExpects(
            $repository,
            'getFailed',
            ['-1'],
            value: new Collection([$job]),
        );
        dashboardExpects($repository, 'countFailed', value: 1);
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            mockDashboardContract(TagRepository::class),
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        getJson('/horizon/failed', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'FailedJobs/Index',
            'X-Inertia-Partial-Data' => 'jobs',
            'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
        ])
            ->assertOk()
            ->assertJsonPath('props.jobs.total', 1)
            ->assertJsonPath('props.jobs.data.0.id', 'failed-1')
            ->assertJsonPath('prependProps.0', 'jobs.data')
            ->assertJsonPath('matchPropsOn.0', 'jobs.data.id')
            ->assertJsonMissingPath('props.jobs.actions')
            ->assertJsonMissingPath('props.listRevision')
            ->assertJsonMissingPath('props.actions');
    });

    it('returns the failed list revision without returning the infinite-scroll prop', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getFailed', new Collection([
            horizonJob(0, 'latest-failed-job'),
        ]));
        dashboardReturns($repository, 'countFailed', 1);
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            mockDashboardContract(TagRepository::class),
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        getJson('/horizon/failed', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'FailedJobs/Index',
            'X-Inertia-Partial-Data' => 'listRevision',
        ])
            ->assertOk()
            ->assertJsonPath('props.listRevision', '[1,"latest-failed-job"]')
            ->assertJsonMissingPath('props.jobs')
            ->assertJsonMissingPath('scrollProps.jobs')
            ->assertJsonMissingPath('prependProps')
            ->assertJsonMissingPath('matchPropsOn');
    });

    it('offers individual retry but not retry all after a prior retry failed', function (): void {
        $job = horizonJob(0, 'failed-1');
        $job->retried_by = json_encode([
            ['id' => 'retry-1', 'status' => 'failed'],
        ], JSON_THROW_ON_ERROR);

        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getFailed', new Collection([$job]));
        dashboardReturns($repository, 'countFailed', 1);
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            mockDashboardContract(TagRepository::class),
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        get('/horizon/failed')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('jobs.total', 1)
                ->where('actions.retryable', false)
                ->missing('jobs.actions')
                ->where('jobs.data.0.retryEligible', true));
    });

    it('makes a failed retry independently retryable after its parent was cleared', function (): void {
        $job = horizonJob(0, 'retry-child');
        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        $job->payload = json_encode([...$payload, 'retry_of' => 'cleared-parent'], JSON_THROW_ON_ERROR);

        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getFailed', new Collection([$job]));
        dashboardReturns($repository, 'countFailed', 1);
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            mockDashboardContract(TagRepository::class),
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        get('/horizon/failed')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('actions.retryable', true)
                ->missing('jobs.actions')
                ->where('jobs.data.0.id', 'retry-child')
                ->where('jobs.data.0.retryOf', 'cleared-parent')
                ->where('jobs.data.0.retryEligible', true));
    });

    it('keeps global bulk actions available when the retained count exceeds the former ceiling', function (): void {

        $job = horizonJob(0, 'matching-failed-job');
        $repository = mockDashboardContract(JobRepository::class);
        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($repository, 'getFailed', new Collection([$job]));
        dashboardReturns($repository, 'countFailed', 1001);
        dashboardReturnsFor($tags, 'paginate', ['failed:tenant:42', 0, 51], ['matching-failed-job']);
        dashboardReturnsFor($tags, 'count', ['failed:tenant:42'], 1);
        dashboardReturnsFor(
            $repository,
            'getJobs',
            [['matching-failed-job'], 0],
            new Collection([$job]),
        );
        app()->instance(FailedJobsData::class, new FailedJobsData(
            $repository,
            $tags,
            new JobsData($repository),
            new FailedJobRetryEligibility,
        ));

        get('/horizon/failed?tag=tenant%3A42')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('jobs.total', 1)
                ->where('actions.hasFailedJobs', true)
                ->where('actions.retryable', true)
                ->where('actions.retryUnavailableReason', null)
                ->where('actions.clearable', true)
                ->where('actions.clearUnavailableReason', null)
                ->missing('jobs.actions'));
    });

    it('retries one failed job and redirects with feedback', function (): void {
        Bus::fake();
        bindFailedJobRetryLockWithoutRedis();

        $job = horizonJob(0, 'failed-1');
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($repository, 'findFailed', ['failed-1'], $job);
        app()->instance(JobRepository::class, $repository);

        post('/horizon/failed/failed-1/retry')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Retry scheduled for failed-1.');

        Bus::assertDispatched(
            HorizonRetryFailedJob::class,
            fn (HorizonRetryFailedJob $job): bool => $job->id === 'failed-1',
        );
    });

    it('reports when one failed job is no longer eligible for retry', function (): void {
        Bus::fake();
        bindFailedJobRetryLockWithoutRedis();

        $job = horizonJob(0, 'failed-1');
        $job->retried_by = json_encode([
            ['id' => 'retry-1', 'status' => 'completed'],
        ], JSON_THROW_ON_ERROR);
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($repository, 'findFailed', ['failed-1'], $job);
        app()->instance(JobRepository::class, $repository);

        post('/horizon/failed/failed-1/retry')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.error',
                'No retry was scheduled because failed-1 is no longer eligible.',
            );

        Bus::assertNothingDispatched();
    });

    it('queues retrying every eligible failed job', function (): void {
        Bus::fake();
        bindFailedJobsAsyncBulkQueue();

        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'countFailed', 1);
        app()->instance(JobRepository::class, $repository);

        post('/horizon/failed/retry-all')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Retrying all failed jobs was queued.');

        Bus::assertDispatched(
            RetryAllFailedJobsJob::class,
            fn (RetryAllFailedJobsJob $job): bool => $job->connectionName === null
                && $job->queueName === null
                && $job->connection === 'operations'
                && $job->queue === 'horizon-maintenance',
        );
        Bus::assertDispatchedTimes(RetryAllFailedJobsJob::class, 1);
        Bus::assertNotDispatched(HorizonRetryFailedJob::class);
    });

    it('queues retry all immediately when the retained count exceeds the former ceiling', function (): void {
        Bus::fake();
        bindFailedJobsAsyncBulkQueue();

        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'countFailed', 1001);
        app()->instance(JobRepository::class, $repository);

        post('/horizon/failed/retry-all')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Retrying all failed jobs was queued.');

        Bus::assertDispatched(
            RetryAllFailedJobsJob::class,
            fn (RetryAllFailedJobsJob $job): bool => $job->connectionName === null
                && $job->queueName === null
                && $job->operationId === null
                && $job->connection === 'operations'
                && $job->queue === 'horizon-maintenance',
        );
        Bus::assertDispatchedTimes(RetryAllFailedJobsJob::class, 1);
        Bus::assertNotDispatched(HorizonRetryFailedJob::class);
    });

    it('removes a failed job from Horizon and Laravel storage', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($repository, 'deleteFailed', ['failed-1'], 1);
        app()->instance(JobRepository::class, $repository);

        $failedJobs = mockDashboardContract(FailedJobProviderInterface::class);
        dashboardReturnsFor($failedJobs, 'forget', ['failed-1'], true);
        app()->instance(FailedJobProviderInterface::class, $failedJobs);

        delete('/horizon/failed/failed-1')
            ->assertRedirect('/horizon/failed')
            ->assertSessionHas('toast.success', 'Removed failed job failed-1.');
    });

    it('queues clearing every failed job', function (): void {
        Bus::fake();
        bindFailedJobsAsyncBulkQueue();

        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'countFailed', 1);
        app()->instance(JobRepository::class, $repository);

        delete('/horizon/failed')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Clearing all failed jobs was queued.');

        Bus::assertDispatched(
            ClearFailedJobsJob::class,
            fn (ClearFailedJobsJob $job): bool => $job->connection === 'operations'
                && $job->queue === 'horizon-maintenance',
        );
        Bus::assertDispatchedTimes(ClearFailedJobsJob::class, 1);
    });

    it('queues clear all immediately when the retained count exceeds the former ceiling', function (): void {
        Bus::fake();
        bindFailedJobsAsyncBulkQueue();

        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'countFailed', 1001);
        app()->instance(JobRepository::class, $repository);

        delete('/horizon/failed')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Clearing all failed jobs was queued.');

        Bus::assertDispatched(
            ClearFailedJobsJob::class,
            fn (ClearFailedJobsJob $job): bool => $job->operationId === null
                && $job->connection === 'operations'
                && $job->queue === 'horizon-maintenance',
        );
        Bus::assertDispatchedTimes(ClearFailedJobsJob::class, 1);
    });

    it('reports bulk queue configuration failures without touching failed jobs', function (): void {
        Bus::fake();
        Exceptions::fake();
        bindFailedJobsSyncBulkQueue();

        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'countFailed', 1);
        app()->instance(JobRepository::class, $repository);

        post('/horizon/failed/retry-all')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );

        delete('/horizon/failed')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );

        Bus::assertNothingDispatched();
        Exceptions::assertReportedCount(2);
    });

    it('honors Horizon authorization for failed-job mutations', function (): void {
        Horizon::auth(static fn (): bool => false);

        post('/horizon/failed/failed-1/retry')->assertForbidden();
        post('/horizon/failed/retry-all')->assertForbidden();
        delete('/horizon/failed')->assertForbidden();
        delete('/horizon/failed/failed-1')->assertForbidden();

        Bus::fake();
        Bus::assertNothingDispatched();
    });
});
