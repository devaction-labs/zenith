<?php

declare(strict_types=1);

use DevactionLabs\Zenith\BulkOperations\Jobs\ClearFailedJobsJob;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryAllFailedJobsJob;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;

use function DevactionLabs\Zenith\Tests\Support\bulkSnapshotRedis;
use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function Pest\Laravel\delete;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutMiddleware;

/** @param 'never'|'once'|'twice'|'zeroOrMoreTimes' $times */
function bindSelectedFailedJobsAsyncBulkQueue(string $times = 'once'): void
{
    config()->set('zenith.bulk_operations.connection', 'operations');
    config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], times: $times, value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

it('queues retrying an explicit selection of failed job ids', function (): void {
    Bus::fake();
    bulkSnapshotRedis();
    bindSelectedFailedJobsAsyncBulkQueue();

    postJson('/horizon/failed/retry-selected', ['ids' => ['failed-1', 'failed-2']])
        ->assertRedirect();

    Bus::assertDispatchedTimes(RetryAllFailedJobsJob::class, 1);
    Bus::assertDispatched(
        RetryAllFailedJobsJob::class,
        fn (RetryAllFailedJobsJob $job): bool => $job->connectionName === null
            && $job->queueName === null,
    );
});

it('rejects an empty selection when retrying selected failed jobs', function (): void {
    postJson('/horizon/failed/retry-selected', ['ids' => []])
        ->assertJsonValidationErrors('ids');
});

it('forbids retrying selected failed jobs when the retryJobs gate is denied', function (): void {
    Gate::define('zenith.retryJobs', static fn (): bool => false);

    postJson('/horizon/failed/retry-selected', ['ids' => ['failed-1']])->assertForbidden();
});

it('queues removing an explicit selection of failed job ids', function (): void {
    Bus::fake();
    bulkSnapshotRedis();
    bindSelectedFailedJobsAsyncBulkQueue();

    delete('/horizon/failed/selected', ['ids' => ['failed-1', 'failed-2']])
        ->assertSessionHas('toast.success', 'Removing 2 selected failed jobs was queued.')
        ->assertRedirect();

    Bus::assertDispatchedTimes(ClearFailedJobsJob::class, 1);
});

it('reports bulk queue configuration failures without removing selected failed jobs', function (): void {
    Bus::fake();
    Exceptions::fake();
    bulkSnapshotRedis();
    config()->set('zenith.bulk_operations.connection', 'sync');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['sync'], value: new SyncQueue);
    app()->instance(QueueManager::class, $manager);

    delete('/horizon/failed/selected', ['ids' => ['failed-1']])
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'The bulk operation could not be queued. Check the application logs and try again.',
        );

    Bus::assertNothingDispatched();
    Exceptions::assertReportedCount(1);
});

it('forbids removing selected failed jobs when the clearQueues gate is denied', function (): void {
    Gate::define('zenith.clearQueues', static fn (): bool => false);

    delete('/horizon/failed/selected', ['ids' => ['failed-1']])->assertForbidden();
});
