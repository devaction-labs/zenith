<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/RetainedJobBrowserFixtures.php';

use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Jobs\RetainedJobFilterCatalog;
use DevactionLabs\Zenith\Jobs\RetainedJobIndex;
use DevactionLabs\Zenith\Jobs\RetainedJobQuery;
use DevactionLabs\Zenith\Jobs\RetainedJobType;
use DevactionLabs\Zenith\Tests\Support\HorizonJob;
use DevactionLabs\Zenith\Tests\Support\RetainedJobBrowserRedisConnection;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\bindBrowserPageFixtures;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

it('renders scheduled jobs as delayed, released, and reserved through the retained query path', function (): void {
    $now = (float) time();
    $released = pendingStatusBrowserJob(0, 'released-job', $now - 120, $now - 60);
    $released->delay = 0;
    $reserved = pendingStatusBrowserJob(1, 'reserved-job', $now - 119, $now - 59);
    $reserved->status = 'reserved';
    $reserved->delay = 0;
    $delayed = pendingStatusBrowserJob(2, 'delayed-job', $now, $now + 3_600);

    bindPendingStatusBrowserFixtures([
        'released-job' => $released,
        'reserved-job' => $reserved,
        'delayed-job' => $delayed,
    ]);

    $page = visit('/horizon/jobs/pending')
        ->waitForText('Released')
        ->assertSee('Reserved')
        ->assertSee('Delayed');

    $page
        ->click('a[href$="/released-job"]')
        ->assertAriaAttribute(
            '[role="status"][aria-label="Job status: Released"]',
            'label',
            'Job status: Released',
        )
        ->assertSee('Created at')
        ->assertSee('Scheduled at')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

it('renders a retry as ready instead of released through the retained query path', function (): void {
    $now = (float) time();
    $retry = pendingStatusBrowserJob(0, 'retry-job', $now - 120, $now - 60);
    $retry->delay = 0;
    $retryPayload = json_decode($retry->payload, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($retryPayload)) {
        throw new LogicException('Expected the retry job payload to decode to an array.');
    }

    $retryPayload['retry_of'] = 'failed-parent';
    $retry->payload = json_encode($retryPayload, JSON_THROW_ON_ERROR);

    bindPendingStatusBrowserFixtures(['retry-job' => $retry]);

    $page = visit('/horizon/jobs/pending')
        ->waitForText('Retry')
        ->assertSee('Ready')
        ->assertDontSee('Released');

    $page
        ->click('a[href$="/retry-job"]')
        ->assertAriaAttribute(
            '[role="status"][aria-label="Job status: Ready"]',
            'label',
            'Job status: Ready',
        )
        ->assertSee('Created at')
        ->assertDontSee('Scheduled at')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

/** @param array<string, HorizonJob> $jobsById */
function bindPendingStatusBrowserFixtures(array $jobsById): void
{
    bindBrowserPageFixtures();

    $source = [];

    foreach (array_keys($jobsById) as $index => $id) {
        $source[$id] = (float) -($index + 1);
    }

    $connection = new RetainedJobBrowserRedisConnection;
    $connection->seedSortedSet(RetainedJobType::Pending->sourceKey(), $source);
    $redis = mockDashboardContract(RedisFactory::class);
    dashboardReturns($redis, 'connection', $connection);

    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturns($repository, 'trimRecentJobs', null);
    dashboardReturns($repository, 'trimFailedJobs', null);
    $repository->shouldNotReceive('getPending');
    dashboardReturnsUsing(
        $repository,
        'getJobs',
        static function (array $ids) use ($jobsById): Collection {
            return new Collection(array_values(array_filter(array_map(
                static function (mixed $id) use ($jobsById): ?HorizonJob {
                    if (! is_string($id)) {
                        throw new LogicException('Expected Horizon job ids to be strings.');
                    }

                    return isset($jobsById[$id]) ? clone $jobsById[$id] : null;
                },
                $ids,
            ))));
        },
    );
    app()->instance(JobRepository::class, $repository);

    $index = new RetainedJobIndex($redis, $repository);
    $query = new RetainedJobQuery($repository, $index);

    app()->instance(JobsData::class, new JobsData(
        jobs: $repository,
        redis: $redis,
        retainedQuery: $query,
        filterCatalog: new RetainedJobFilterCatalog($index),
    ));
}

function pendingStatusBrowserJob(
    int $index,
    string $id,
    float $pushedAt,
    float $releaseAt,
): HorizonJob {
    $job = horizonJob($index, $id);
    $job->name = 'App\\Jobs\\PendingJob'.str_pad((string) $index, 5, '0', STR_PAD_LEFT);
    $job->status = 'pending';
    $job->completed_at = null;
    $delay = max(1, (int) ($releaseAt - $pushedAt));
    $job->delay = $delay;
    $job->updated_at = (string) $pushedAt;
    $job->payload = json_encode([
        'uuid' => $id,
        'displayName' => $job->name,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'pushedAt' => $pushedAt,
        'createdAt' => (int) $pushedAt,
        'delay' => $delay,
        'data' => [
            'commandName' => $job->name,
            'command' => serialize((object) ['delay' => $delay]),
        ],
    ], JSON_THROW_ON_ERROR);

    return $job;
}
