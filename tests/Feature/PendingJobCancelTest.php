<?php

declare(strict_types=1);

use DevactionLabs\Zenith\BulkOperations\Jobs\CancelPendingJobsJob;
use DevactionLabs\Zenith\Jobs\PendingJobCancellationScope;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Horizon;

use function DevactionLabs\Zenith\Tests\Support\bindBrowserPageFixtures;
use function DevactionLabs\Zenith\Tests\Support\bulkSnapshotRedis;
use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\delete;
use function Pest\Laravel\withoutMiddleware;

/** @param 'never'|'once'|'twice'|'zeroOrMoreTimes' $times */
function bindPendingCancellationAsyncBulkQueue(string $times = 'once'): void
{
    config()->set('zenith.bulk_operations.connection', 'operations');
    config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], times: $times, value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

function bindPendingCancellationSyncBulkQueue(): void
{
    config()->set('zenith.bulk_operations.connection', 'sync');
    config()->set('zenith.bulk_operations.queue', null);

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['sync'], value: new SyncQueue);
    app()->instance(QueueManager::class, $manager);
}

/** @param array<int, object> $pendingJobs */
function bindPendingCancellationJobs(array $pendingJobs): void
{
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countPending', count($pendingJobs));
    dashboardReturns($jobs, 'getPending', new Collection($pendingJobs));
    app()->instance(JobRepository::class, $jobs);
}

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    bindBrowserPageFixtures();
});

it('cancels an individual pending job', function (): void {
    delete('/horizon/jobs/pending/pending-1')
        ->assertSessionHas('toast.success', 'Job cancelled.')
        ->assertRedirect('/horizon/jobs/pending');
});

it('queues cancelling pending jobs in the requested state and queue', function (): void {
    Bus::fake();
    bindPendingCancellationAsyncBulkQueue();

    $pending = horizonJob(0, 'pending-reports');
    $pending->queue = 'reports';
    bindPendingCancellationJobs([$pending]);

    delete('/horizon/jobs/pending/cancel/delayed?queue=reports')
        ->assertSessionHas('toast.success', 'Cancelling delayed jobs from reports was queued.')
        ->assertRedirect();

    Bus::assertDispatched(
        CancelPendingJobsJob::class,
        fn (CancelPendingJobsJob $job): bool => $job->scope === PendingJobCancellationScope::Delayed
            && $job->queueName === 'reports'
            && $job->connection === 'operations'
            && $job->queue === 'horizon-maintenance',
    );
    Bus::assertDispatchedTimes(CancelPendingJobsJob::class, 1);
});

it('reports bulk queue configuration failures without cancelling pending jobs', function (): void {
    Bus::fake();
    Exceptions::fake();
    bindPendingCancellationSyncBulkQueue();

    bindPendingCancellationJobs([]);

    delete('/horizon/jobs/pending/cancel/pending')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'The bulk operation could not be queued. Check the application logs and try again.',
        );

    Bus::assertNothingDispatched();
    Exceptions::assertReportedCount(1);
});

it('queues cancellation when the requested queue scope exceeds the former ceiling', function (): void {
    Bus::fake();
    bindPendingCancellationAsyncBulkQueue();

    $first = horizonJob(0, 'pending-reports-1');
    $first->queue = 'reports';
    $second = horizonJob(1, 'pending-reports-2');
    $second->queue = 'reports';
    $other = horizonJob(2, 'pending-default');
    $other->queue = 'default';
    bindPendingCancellationJobs([$first, $second, $other]);

    delete('/horizon/jobs/pending/cancel/pending?queue=reports')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Cancelling pending jobs from reports was queued.');

    Bus::assertDispatched(CancelPendingJobsJob::class);
});

it('rejects unsupported pending cancellation scopes', function (): void {
    delete('/horizon/jobs/pending/cancel/reserved')->assertStatus(405);
});

it('queues cancelling an explicit selection of pending job ids', function (): void {
    Bus::fake();
    bulkSnapshotRedis();
    bindPendingCancellationAsyncBulkQueue();

    delete('/horizon/jobs/pending/cancel-selected', ['ids' => ['pending-1', 'pending-2']])
        ->assertSessionHas('toast.success', 'Cancelling 2 selected jobs was queued.')
        ->assertRedirect();

    Bus::assertDispatchedTimes(CancelPendingJobsJob::class, 1);
    Bus::assertDispatched(
        CancelPendingJobsJob::class,
        fn (CancelPendingJobsJob $job): bool => $job->scope === PendingJobCancellationScope::Pending
            && $job->queueName === null,
    );
});

it('rejects an empty selection when cancelling selected pending jobs', function (): void {
    delete('/horizon/jobs/pending/cancel-selected', ['ids' => []])
        ->assertSessionHasErrors('ids');
});

it('forbids cancelling selected pending jobs when the cancelJobs gate is denied', function (): void {
    Gate::define('zenith.cancelJobs', static fn (): bool => false);

    delete('/horizon/jobs/pending/cancel-selected', ['ids' => ['pending-1']])->assertForbidden();
});
