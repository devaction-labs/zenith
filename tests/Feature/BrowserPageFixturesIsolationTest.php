<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Bus;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\CancelPendingJobsJob;
use NckRtl\HorizonNewDawn\Dashboard\DashboardData;
use NckRtl\HorizonNewDawn\Jobs\PendingJobCancellationScope;

use function NckRtl\HorizonNewDawn\Tests\Support\bindBrowserPageFixtures;

/**
 * Browser suites must not depend on a live Horizon Redis (CI main tests job has none).
 * Fixtures must bind the same contracts DashboardData and bulk dispatch resolve from
 * the container — creating Mockery doubles alone is not enough.
 */
function isolateBrowserFixturesFromRedis(): void
{
    config()->set('database.redis.default.port', 1);
    config()->set('database.redis.horizon.port', 1);
    app()->forgetInstance('redis');
    app()->forgetInstance(RedisManager::class);
    app()->forgetInstance(RedisFactory::class);
}

it('binds horizon repositories so dashboard data does not require Redis', function (): void {
    isolateBrowserFixturesFromRedis();
    bindBrowserPageFixtures();

    expect(app(MasterSupervisorRepository::class)->all())->not->toBeEmpty()
        ->and(app(SupervisorRepository::class)->all())->not->toBeEmpty();

    $summary = app(DashboardData::class)->summary();
    $supervisors = app(DashboardData::class)->supervisors();

    expect($summary->available)->toBeTrue()
        ->and($summary->message)->toBeNull()
        ->and($supervisors->available)->toBeTrue()
        ->and($supervisors->groups)->not->toBeEmpty()
        ->and($supervisors->groups[0]->local)->toBeTrue();
});

it('queues bulk cancellations through a deterministic non-Redis bulk path', function (): void {
    isolateBrowserFixturesFromRedis();
    bindBrowserPageFixtures();

    app(BulkOperationDispatcher::class)->dispatch(
        new CancelPendingJobsJob(PendingJobCancellationScope::Delayed),
    );

    Bus::assertDispatched(
        CancelPendingJobsJob::class,
        fn (CancelPendingJobsJob $job): bool => $job->scope === PendingJobCancellationScope::Delayed
            && $job->connection === 'operations'
            && $job->queue === 'horizon-maintenance',
    );
});
