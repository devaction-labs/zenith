<?php

declare(strict_types=1);

use DevactionLabs\Zenith\BulkOperations\Jobs\ClearPendingJobsJob;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Horizon;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\delete;
use function Pest\Laravel\withoutMiddleware;

/** @param 'never'|'once'|'twice'|'zeroOrMoreTimes' $times */
function bindQueueClearAllAsyncBulkQueue(string $times = 'once'): void
{
    config()->set('zenith.bulk_operations.connection', 'operations');
    config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], times: $times, value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

function bindQueueClearAllSyncBulkQueue(): void
{
    config()->set('zenith.bulk_operations.connection', 'sync');
    config()->set('zenith.bulk_operations.queue', null);

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['sync'], value: new SyncQueue);
    app()->instance(QueueManager::class, $manager);
}

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

it('queues clearing every queue', function (): void {
    Bus::fake();
    bindQueueClearAllAsyncBulkQueue();

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countPending', 1);
    app()->instance(JobRepository::class, $jobs);

    delete('/horizon/queues')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Clearing all queues was queued.');

    Bus::assertDispatched(
        ClearPendingJobsJob::class,
        fn (ClearPendingJobsJob $job): bool => $job->connection === 'operations'
            && $job->queue === 'horizon-maintenance',
    );
    Bus::assertDispatchedTimes(ClearPendingJobsJob::class, 1);
});

it('reports bulk queue configuration failures without clearing queues', function (): void {
    Bus::fake();
    Exceptions::fake();
    bindQueueClearAllSyncBulkQueue();

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countPending', 1);
    app()->instance(JobRepository::class, $jobs);

    delete('/horizon/queues')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'The bulk operation could not be queued. Check the application logs and try again.',
        );

    Bus::assertNothingDispatched();
    Exceptions::assertReportedCount(1);
});

it('queues clearing every queue when the retained scope exceeds the former ceiling', function (): void {
    Bus::fake();
    bindQueueClearAllAsyncBulkQueue();

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countPending', 1001);
    app()->instance(JobRepository::class, $jobs);

    delete('/horizon/queues')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Clearing all queues was queued.');

    Bus::assertDispatched(ClearPendingJobsJob::class);
});

it('honors Horizon authorization when clearing all queues', function (): void {
    Horizon::auth(static fn (): bool => false);

    delete('/horizon/queues')->assertForbidden();
});
