<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Assets\AssetManifest;
use DevactionLabs\Zenith\BulkOperations\Jobs\ClearRecentJobsJob;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryMonitoredFailedJobsJob;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Monitoring\MonitoringData;
use DevactionLabs\Zenith\Support\HorizonRuntime;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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
use Laravel\Horizon\Jobs\MonitorTag as HorizonMonitorTag;
use Laravel\Horizon\Jobs\StopMonitoringTag as HorizonStopMonitoringTag;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

/** @param 'never'|'once'|'twice'|'zeroOrMoreTimes' $times */
function bindMonitoringAsyncBulkQueue(string $times = 'once'): void
{
    config()->set('zenith.bulk_operations.connection', 'operations');
    config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], times: $times, value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

function bindMonitoringSyncBulkQueue(): void
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

describe('monitoring pages', function (): void {
    it('renders monitored tags and route-backed recent and failed tabs', function (): void {
        config()->set('horizon.silenced_tags', ['checkout']);
        config()->set('horizon.trim.monitored', 120);
        config()->set('horizon.trim.failed', 240);

        $recent = horizonJob(0, 'job-1');
        $failed = horizonJob(0, 'failed-1');
        $failed->completed_at = null;
        $failed->failed_at = '1784281003.00';

        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($tags, 'monitoring', ['checkout']);
        dashboardReturnsUsing($tags, 'count', static fn (string $tag): int => match ($tag) {
            'checkout' => 2,
            'failed:checkout' => 1,
            default => 0,
        });
        dashboardReturnsUsing($tags, 'paginate', static function (string $tag, int $startingAt = 0, int $limit = 25): array {
            return match (true) {
                $tag === 'checkout' && $limit === 1 => [0 => 'job-1'],
                $tag === 'failed:checkout' && $limit === 1 => [0 => 'failed-1'],
                $tag === 'checkout' => [0 => 'job-1'],
                $tag === 'failed:checkout' => [0 => 'failed-1'],
                default => [],
            };
        });
        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturnsUsing($jobs, 'getJobs', function (array $ids) use ($recent, $failed): Collection {
            $map = [
                'job-1' => $recent,
                'failed-1' => $failed,
            ];

            return new Collection(array_values(array_filter(
                array_map(
                    static fn (mixed $id): ?object => is_string($id)
                        ? ($map[$id] ?? null)
                        : throw new LogicException('Expected Horizon job ids to be strings.'),
                    $ids,
                ),
            )));
        });
        app()->instance(MonitoringData::class, new MonitoringData($tags, $jobs, new JobsData($jobs)));

        get('/horizon/monitoring')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Monitoring/Index')
                ->where('meta.activeNavigation', 'monitoring')
                ->where('tags.data.0.tag', 'checkout')
                ->where('tags.data.0.trackedCount', 2)
                ->where('tags.data.0.failedCount', 1)
                ->where('tags.data.0.silenced', true)
                ->where('monitoredTags', ['checkout']));

        get('/horizon/monitoring/checkout/jobs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Monitoring/Show')
                ->where('tag', 'checkout')
                ->where('status', 'jobs')
                ->where('summary.trackedCount', 2)
                ->where('summary.failedCount', 1)
                ->where('summary.silenced', true)
                ->where('summary.monitoredRetentionMinutes', 120)
                ->where('summary.failedRetentionMinutes', 240)
                ->where('listRevision', '[2,"job-1"]')
                ->where('jobs.data.0.id', 'job-1'));

        get('/horizon/monitoring/checkout/failed')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Monitoring/Show')
                ->where('status', 'failed')
                ->where('listRevision', '[1,"failed-1"]')
                ->where('jobs.data.0.id', 'failed-1'));
    });

    it('renders a monitored tag containing an encoded slash', function (): void {
        $recent = horizonJob(0, 'job-1');
        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($tags, 'monitoring', ['customer/42']);
        dashboardReturnsUsing($tags, 'count', static fn (string $tag): int => $tag === 'customer/42' ? 1 : 0);
        dashboardReturnsUsing($tags, 'paginate', static fn (string $tag): array => $tag === 'customer/42' ? ['job-1'] : []);

        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturns($jobs, 'getJobs', new Collection([$recent]));

        app()->instance(MonitoringData::class, new MonitoringData($tags, $jobs, new JobsData($jobs)));

        get('/horizon/monitoring/customer%2F42/jobs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Monitoring/Show')
                ->where('tag', 'customer/42')
                ->where('status', 'jobs')
                ->where('jobs.data.0.id', 'job-1'));
    });

    it('only resolves jobs once and preserves their scroll metadata during a jobs-only reload', function (): void {
        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturnsFor($tags, 'paginate', ['checkout', 0, 51], []);
        dashboardReturnsFor($tags, 'count', ['checkout'], 0);
        dashboardNeverReceives($tags, 'monitoring');
        dashboardExpects($tags, 'count', ['failed:checkout'], times: 'never');

        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($jobs, 'getJobs', [[], 0], new Collection);

        app()->instance(MonitoringData::class, new MonitoringData($tags, $jobs, new JobsData($jobs)));

        getJson('/horizon/monitoring/checkout/jobs', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Monitoring/Show',
            'X-Inertia-Partial-Data' => 'jobs',
            'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
        ])
            ->assertOk()
            ->assertJsonPath('component', 'Monitoring/Show')
            ->assertJsonPath('props.jobs.available', true)
            ->assertJsonMissingPath('props.listRevision')
            ->assertJsonMissingPath('props.summary')
            ->assertJsonPath('prependProps.0', 'jobs.data')
            ->assertJsonPath('matchPropsOn.0', 'jobs.data.id')
            ->assertJsonPath('scrollProps.jobs.pageName', 'starting_at')
            ->assertJsonPath('scrollProps.jobs.previousPage', null)
            ->assertJsonPath('scrollProps.jobs.nextPage', null)
            ->assertJsonPath('scrollProps.jobs.currentPage', 0);
    });

    it('returns the monitored list revision without returning the infinite-scroll prop', function (): void {
        $job = horizonJob(0, 'latest-monitored-job');
        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturnsFor($tags, 'paginate', ['checkout', 0, 51], ['latest-monitored-job']);
        dashboardReturnsFor($tags, 'count', ['checkout'], 1);

        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor(
            $jobs,
            'getJobs',
            [['latest-monitored-job'], 0],
            new Collection([$job]),
        );

        app()->instance(MonitoringData::class, new MonitoringData($tags, $jobs, new JobsData($jobs)));

        getJson('/horizon/monitoring/checkout/jobs', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(AssetManifest::class)->version(),
            'X-Inertia-Partial-Component' => 'Monitoring/Show',
            'X-Inertia-Partial-Data' => 'listRevision',
        ])
            ->assertOk()
            ->assertJsonPath('props.listRevision', '[1,"latest-monitored-job"]')
            ->assertJsonMissingPath('props.jobs')
            ->assertJsonMissingPath('scrollProps.jobs')
            ->assertJsonMissingPath('prependProps')
            ->assertJsonMissingPath('matchPropsOn');
    });

    it('validates and trims a new monitored tag', function (): void {
        Bus::fake();

        post('/horizon/monitoring', ['tag' => '   '])
            ->assertRedirect()
            ->assertSessionHasErrors('tag');

        post('/horizon/monitoring', ['tag' => '  checkout  '])
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Now monitoring checkout.');

        Bus::assertDispatched(
            HorizonMonitorTag::class,
            fn (HorizonMonitorTag $job): bool => $job->tag === 'checkout',
        );
    });

    it('rejects tags that collide with Horizon storage', function (string $tag): void {
        Bus::fake();

        post('/horizon/monitoring', ['tag' => $tag])
            ->assertRedirect()
            ->assertSessionHasErrors('tag');

        Bus::assertNotDispatched(HorizonMonitorTag::class);
    })->with([
        'pending jobs index' => 'pending_jobs',
        'failed jobs index' => 'failed_jobs',
        'global job id counter' => 'job_id',
        'master index' => 'masters',
        'command queue namespace' => 'commands:horizon-host',
        'failed tag namespace' => 'failed:checkout',
        'job metrics namespace' => 'snapshot:job:App\\Jobs\\ImportUsers',
        'notification lock namespace' => 'notification:long-wait',
        'orphan process namespace' => 'horizon-host:orphans',
    ]);

    it('allows ordinary application tag formats', function (string $tag): void {
        Bus::fake();

        post('/horizon/monitoring', ['tag' => $tag])
            ->assertRedirect()
            ->assertSessionHas('toast.success', "Now monitoring {$tag}.");

        Bus::assertDispatched(
            HorizonMonitorTag::class,
            fn (HorizonMonitorTag $job): bool => $job->tag === $tag,
        );
    })->with([
        'model tag' => 'App\\Models\\User:42',
        'tenant tag' => 'tenant:acme',
        'namespace tag' => 'namespace\\job',
        'slash tag' => 'customer/42',
    ]);

    it('stops monitoring a tag and honors Horizon authorization', function (): void {
        Bus::fake();
        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($tags, 'monitoring', ['checkout']);
        app()->instance(TagRepository::class, $tags);

        delete('/horizon/monitoring/actions/stop/checkout')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Stopped monitoring checkout.');

        Bus::assertDispatched(
            HorizonStopMonitoringTag::class,
            fn (HorizonStopMonitoringTag $job): bool => $job->tag === 'checkout',
        );

        Horizon::auth(static fn (): bool => false);
        post('/horizon/monitoring', ['tag' => 'denied'])->assertForbidden();
    });

    it('stops monitoring a slash-bearing tag beginning with an action-like segment', function (): void {
        Bus::fake();
        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($tags, 'monitoring', ['jobs/customer']);
        app()->instance(TagRepository::class, $tags);

        delete('/horizon/monitoring/actions/stop/jobs%2Fcustomer')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Stopped monitoring jobs/customer.');

        Bus::assertDispatched(
            HorizonStopMonitoringTag::class,
            fn (HorizonStopMonitoringTag $job): bool => $job->tag === 'jobs/customer',
        );
    });

    it('queues clearing recent jobs for a monitored tag', function (): void {
        Bus::fake();
        bindMonitoringAsyncBulkQueue();

        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($tags, 'count', 1);
        app()->instance(TagRepository::class, $tags);

        delete('/horizon/monitoring/actions/clear-jobs/customer%2Fjobs')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Clearing recent jobs from customer/jobs was queued.');

        Bus::assertDispatched(
            ClearRecentJobsJob::class,
            fn (ClearRecentJobsJob $job): bool => $job->tag === 'customer/jobs'
                && $job->connection === 'operations'
                && $job->queue === 'horizon-maintenance',
        );
        Bus::assertDispatchedTimes(ClearRecentJobsJob::class, 1);
    });

    it('queues retrying failed jobs for a monitored tag', function (): void {
        Bus::fake();
        bindMonitoringAsyncBulkQueue();

        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($tags, 'count', 1);
        app()->instance(TagRepository::class, $tags);

        post('/horizon/monitoring/actions/retry-failed/customer%2F42')
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Retrying failed jobs tagged customer/42 was queued.');

        Bus::assertDispatched(
            RetryMonitoredFailedJobsJob::class,
            fn (RetryMonitoredFailedJobsJob $job): bool => $job->tag === 'customer/42'
                && $job->connection === 'operations'
                && $job->queue === 'horizon-maintenance',
        );
        Bus::assertDispatchedTimes(RetryMonitoredFailedJobsJob::class, 1);
    });

    it('reports bulk queue configuration failures without mutating monitored jobs', function (): void {
        Bus::fake();
        Exceptions::fake();
        bindMonitoringSyncBulkQueue();

        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturns($tags, 'count', 1);
        app()->instance(TagRepository::class, $tags);

        delete('/horizon/monitoring/actions/clear-jobs/customer%2Fjobs')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );

        post('/horizon/monitoring/actions/retry-failed/customer%2F42')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );

        Bus::assertNothingDispatched();
        Exceptions::assertReportedCount(2);
    });

    it('queues clearing a monitored tag when its retained jobs exceed the former ceiling', function (): void {
        Bus::fake();
        bindMonitoringAsyncBulkQueue();

        delete('/horizon/monitoring/actions/clear-jobs/customer%2Fjobs')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.success',
                'Clearing recent jobs from customer/jobs was queued.',
            );

        Bus::assertDispatched(ClearRecentJobsJob::class);
    });

    it('queues retrying a monitored tag when its failed jobs exceed the former ceiling', function (): void {
        Bus::fake();
        bindMonitoringAsyncBulkQueue();

        post('/horizon/monitoring/actions/retry-failed/customer%2F42')
            ->assertRedirect()
            ->assertSessionHas(
                'toast.success',
                'Retrying failed jobs tagged customer/42 was queued.',
            );

        Bus::assertDispatched(RetryMonitoredFailedJobsJob::class);
    });
});
