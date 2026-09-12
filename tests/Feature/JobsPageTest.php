<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Assets\AssetManifest;
use DevactionLabs\Zenith\Batches\BatchJobsData;
use DevactionLabs\Zenith\Jobs\Data\JobIndexFiltersData;
use DevactionLabs\Zenith\Jobs\JobListType;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Jobs\PendingJobEntryScanner;
use DevactionLabs\Zenith\Jobs\PendingJobStateIndex;
use DevactionLabs\Zenith\Jobs\RetainedJobCursor;
use DevactionLabs\Zenith\Jobs\RetainedJobFilterCatalog;
use DevactionLabs\Zenith\Jobs\RetainedJobIndex;
use DevactionLabs\Zenith\Jobs\RetainedJobPosition;
use DevactionLabs\Zenith\Jobs\RetainedJobQuery;
use DevactionLabs\Zenith\Jobs\RetainedJobType;
use DevactionLabs\Zenith\Queues\QueuePauseStatus;
use DevactionLabs\Zenith\Queues\QueuesData;
use DevactionLabs\Zenith\Queues\QueueWaitThreshold;
use DevactionLabs\Zenith\Support\HorizonRuntime;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;

use function DevactionLabs\Zenith\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

beforeEach(function (): void {
    $masters = mockDashboardContract(MasterSupervisorRepository::class);
    dashboardReturns($masters, 'all', [(object) ['status' => 'running']]);
    app()->instance(HorizonRuntime::class, new HorizonRuntime($masters));
});

function bindJobsPageQueueCatalog(int $ready, int $delayed): void
{
    $supervisors = mockDashboardContract(SupervisorRepository::class);
    dashboardReturns($supervisors, 'all', [(object) ['processes' => ['redis:default' => 1]]]);

    $queue = mockDashboardContract(Queue::class);
    dashboardReturnsFor($queue, 'readyNow', ['default'], $ready);
    dashboardReturnsFor($queue, 'reservedSize', ['default'], 0);
    dashboardReturnsFor($queue, 'delayedSize', ['default'], $delayed);
    dashboardReturnsFor($queue, 'creationTimeOfOldestPendingJob', ['default'], null);

    $queues = mockDashboardContract(QueueFactory::class);
    dashboardReturnsFor($queues, 'connection', ['redis'], $queue);

    $waits = mockDashboardContract(WaitTimeCalculator::class);
    dashboardReturnsFor($waits, 'calculateTimeToClear', ['redis', 'default', 1], 0);

    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturnsFor($metrics, 'runtimeForQueue', ['default'], 0);

    app()->instance(QueuesData::class, new QueuesData(
        $supervisors,
        $queues,
        $waits,
        $metrics,
        app(QueuePauseStatus::class),
        app(QueueWaitThreshold::class),
    ));
}

describe('job pages', function (): void {
    it('resolves the scoped production query graph from the package container', function (): void {
        $index = app(RetainedJobIndex::class);
        $pendingStates = app(PendingJobStateIndex::class);
        $batchJobs = app(BatchJobsData::class);
        $injectedScanner = (new ReflectionClass($batchJobs))
            ->getProperty('pendingStates')
            ->getValue($batchJobs);

        expect($index)->toBe(app(RetainedJobIndex::class))
            ->and($pendingStates)->toBeInstanceOf(PendingJobStateIndex::class)
            ->and(app(PendingJobEntryScanner::class))->toBe($pendingStates)
            ->and($batchJobs)->toBeInstanceOf(BatchJobsData::class)
            ->and($injectedScanner)->toBe($pendingStates)
            ->and(app(RetainedJobQuery::class))->toBeInstanceOf(RetainedJobQuery::class)
            ->and(app(RetainedJobFilterCatalog::class))
            ->toBeInstanceOf(RetainedJobFilterCatalog::class)
            ->and(app(JobsData::class))->toBeInstanceOf(JobsData::class);
    });

    it('provides live pending counts from every relevant queue', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getPending', new Collection([horizonJob(0)]));
        dashboardReturns($repository, 'countPending', 1);
        app()->instance(JobsData::class, new JobsData($repository));
        bindJobsPageQueueCatalog(4, 7);

        get('/horizon/jobs/pending')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Index')
                ->where('pendingCounts.available', true)
                ->where('pendingCounts.ready', 4)
                ->where('pendingCounts.delayed', 7));
    });

    it('renders the completed job list with scroll-safe rows', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getCompleted', new Collection([horizonJob(0)]));
        dashboardReturns($repository, 'countCompleted', 1);
        app()->instance(JobsData::class, new JobsData($repository));

        get('/horizon/jobs/completed')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Index')
                ->where('meta.title', 'Completed Jobs')
                ->where('meta.activeNavigation', 'completed')
                ->where('type', 'completed')
                ->where('query', '')
                ->where('filters.job', null)
                ->where('filters.queue', null)
                ->where('filters.connection', null)
                ->where('filters.state', null)
                ->missing('sort')
                ->missing('direction')
                ->missing('filterCatalog')
                ->where('querySignature', fn (mixed $value): bool => is_string($value)
                    && strlen($value) === 64)
                ->where('listRevision', '[1,"job-1"]')
                ->where('jobs.data.0.id', 'job-1')
                ->where('jobs.total', 1)
                ->missing('jobs.data.0.payload')
                ->missing('jobs.data.0.exception'));
    });

    it('accepts an exact tag facet alongside the existing job, queue, and connection filters', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getCompleted', new Collection);
        dashboardReturns($repository, 'countCompleted', 0);
        app()->instance(JobsData::class, new JobsData($repository));

        get('/horizon/jobs/completed?filter_tag=tenant%3A42')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('filters.job', null)
                ->where('filters.queue', null)
                ->where('filters.connection', null)
                ->where('filters.tag', 'tenant:42'));
    });

    it('keeps the filter catalog out of the navigation deferred request', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getCompleted', new Collection);
        dashboardReturns($repository, 'countCompleted', 0);
        app()->instance(JobsData::class, new JobsData($repository));

        $response = getJson('/horizon/jobs/completed', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
        ])->assertOk();

        expect($response->json('deferredProps.navigation'))
            ->toContain('navigationCounts')
            ->not()->toContain('filterCatalog');
    });

    it('loads the optional filter catalog without resolving the job page', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardNeverReceives($repository, 'getCompleted');
        dashboardNeverReceives($repository, 'countCompleted');
        app()->instance(JobsData::class, new JobsData($repository));

        getJson('/horizon/jobs/completed', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Jobs/Index',
            'X-Inertia-Partial-Data' => 'filterCatalog',
        ])
            ->assertOk()
            ->assertJsonPath('props.filterCatalog.available', false)
            ->assertJsonMissingPath('props.jobs');
    });

    it('does not resolve pending queue counts during a jobs-only partial reload', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getPending', new Collection);
        dashboardReturns($repository, 'countPending', 0);
        app()->instance(JobsData::class, new JobsData($repository));

        $supervisors = mockDashboardContract(SupervisorRepository::class);
        dashboardNeverReceives($supervisors, 'all');
        app()->instance(QueuesData::class, new QueuesData(
            $supervisors,
            mockDashboardContract(QueueFactory::class),
            mockDashboardContract(WaitTimeCalculator::class),
            mockDashboardContract(MetricsRepository::class),
            app(QueuePauseStatus::class),
            app(QueueWaitThreshold::class),
        ));

        getJson('/horizon/jobs/pending', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Jobs/Index',
            'X-Inertia-Partial-Data' => 'jobs',
            'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
        ])
            ->assertOk()
            ->assertJsonStructure(['props' => ['jobs']])
            ->assertJsonPath('prependProps.0', 'jobs.data')
            ->assertJsonPath('matchPropsOn.0', 'jobs.data.id')
            ->assertJsonMissingPath('props.listRevision')
            ->assertJsonMissingPath('props.pendingCounts');
    });

    it('returns the list revision without returning the infinite-scroll prop', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getCompleted', new Collection([
            horizonJob(0, 'latest-completed-job'),
        ]));
        dashboardReturns($repository, 'countCompleted', 1);
        app()->instance(JobsData::class, new JobsData($repository));

        getJson('/horizon/jobs/completed', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Jobs/Index',
            'X-Inertia-Partial-Data' => 'listRevision',
        ])
            ->assertOk()
            ->assertJsonPath('props.listRevision', '[1,"latest-completed-job"]')
            ->assertJsonMissingPath('props.jobs')
            ->assertJsonMissingPath('scrollProps.jobs')
            ->assertJsonMissingPath('prependProps')
            ->assertJsonMissingPath('matchPropsOn');
    });

    it('rejects an invalid pending state query', function (): void {
        get('/horizon/jobs/pending?filter_state=not-a-state')
            ->assertRedirect()
            ->assertSessionHasErrors('filter_state');
    });

    it('normalizes the retained-source search query', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        app()->instance(JobsData::class, new JobsData($repository));

        get('/horizon/jobs/completed?'.http_build_query([
            'query' => '  ImportFeed  ',
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('query', 'ImportFeed')
                ->where('jobs.available', false));
    });

    it('rejects a search query longer than the request-line budget', function (): void {
        get('/horizon/jobs/completed?'.http_build_query([
            'query' => str_repeat('a', 513),
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors('query');
    });

    it('does not pass browser row sorting into the retained backend query', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getCompleted', new Collection([horizonJob(0)]));
        dashboardReturns($repository, 'countCompleted', 1);
        app()->instance(JobsData::class, new JobsData($repository));

        get('/horizon/jobs/completed?sort=runtime&direction=desc')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->missing('sort')
                ->missing('direction')
                ->where('filters.job', null)
                ->where('filters.queue', null)
                ->where('filters.connection', null)
                ->where('filters.state', null));
    });

    it('accepts a short signed retained-source cursor on page two', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $jobs = new JobsData($repository);
        app()->instance(JobsData::class, $jobs);
        $signature = $jobs->querySignature(
            JobListType::Completed,
            JobIndexFiltersData::none(),
        );
        $position = new RetainedJobPosition(
            score: -1785067200.123456,
            id: 'job-50',
            offset: 50,
        );
        $cursorCodec = new RetainedJobCursor;
        $cursor = $cursorCodec->encode(
            RetainedJobType::Completed,
            $signature,
            $position,
        );

        expect(strlen($cursor))->toBeLessThanOrEqual(2048)
            ->and($cursorCodec->decode(
                $cursor,
                RetainedJobType::Completed,
                $signature,
            ))->toEqual($position);

        get('/horizon/jobs/completed?'.http_build_query([
            'starting_at' => $cursor,
        ]))
            ->assertOk()
            ->assertSessionDoesntHaveErrors('starting_at')
            ->assertInertia(
                fn (AssertableInertia $page): AssertableInertia => $page
                    ->missing('sort')
                    ->missing('direction')
                    ->where('jobs.available', false),
            );
    });

    it('rejects a retained cursor longer than the request-line budget', function (): void {
        get('/horizon/jobs/completed?'.http_build_query([
            'starting_at' => str_repeat('a', 2049),
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors('starting_at');
    });

    it('renders a recent job detail and 404s a missing job', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturns($repository, 'getJobs', new Collection([horizonJob(0)]));
        app()->instance(JobsData::class, new JobsData($repository));

        get('/horizon/jobs/completed/job-1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Show')
                ->where('meta.title', 'Job Detail')
                ->where('job.id', 'job-1')
                ->where('job.payload.displayName', 'App\\Jobs\\ImportFeed')
                ->missing('job.exception'));

        $missingRepository = mockDashboardContract(JobRepository::class);
        dashboardReturns($missingRepository, 'getJobs', new Collection);
        app()->instance(JobsData::class, new JobsData($missingRepository));
        get('/horizon/jobs/completed/missing')->assertNotFound();
    });
});
