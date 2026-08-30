<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\ClearBatchFailedJobsJob;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Horizon\Horizon;

use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardExpects;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturns;
use function DevactionLabs\HorizonNewDawn\Tests\Support\horizonBatch;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;
use function Pest\Laravel\delete;
use function Pest\Laravel\withoutMiddleware;

/** @param 'never'|'once'|'twice'|'zeroOrMoreTimes' $times */
function bindBatchFailedClearAsyncBulkQueue(string $times = 'once'): void
{
    config()->set('horizon-new-dawn.bulk_operations.connection', 'operations');
    config()->set('horizon-new-dawn.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], times: $times, value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

function bindBatchFailedClearSyncBulkQueue(): void
{
    config()->set('horizon-new-dawn.bulk_operations.connection', 'sync');
    config()->set('horizon-new-dawn.bulk_operations.queue', null);

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['sync'], value: new SyncQueue);
    app()->instance(QueueManager::class, $manager);
}

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);

    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturns($batches, 'find', horizonBatch(
        'batch-1',
        failedJobs: 1,
        failedJobIds: ['failed-1'],
    ));
    app()->instance(BatchRepository::class, $batches);
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

it('queues clearing the failed jobs belonging to the selected batch', function (): void {
    Bus::fake();
    bindBatchFailedClearAsyncBulkQueue();

    delete('/horizon/batches/batch-1/failed')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Clearing failed jobs for batch batch-1 was queued.');

    Bus::assertDispatched(
        ClearBatchFailedJobsJob::class,
        fn (ClearBatchFailedJobsJob $job): bool => $job->batchId === 'batch-1'
            && $job->connection === 'operations'
            && $job->queue === 'horizon-maintenance',
    );
    Bus::assertDispatchedTimes(ClearBatchFailedJobsJob::class, 1);
});

it('reports when clearing batch failures cannot use an asynchronous bulk queue', function (): void {
    Bus::fake();
    Exceptions::fake();
    bindBatchFailedClearSyncBulkQueue();

    delete('/horizon/batches/batch-1/failed')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'The bulk operation could not be queued. Check the application logs and try again.',
        );

    Bus::assertNothingDispatched();
    Exceptions::assertReportedCount(1);
});

it('queues clearing a batch when its failed jobs exceed the former ceiling', function (): void {
    Bus::fake();
    bindBatchFailedClearAsyncBulkQueue();

    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturns($batches, 'find', horizonBatch(
        'batch-1',
        failedJobs: 2,
        failedJobIds: ['failed-1', 'failed-2'],
    ));
    app()->instance(BatchRepository::class, $batches);

    delete('/horizon/batches/batch-1/failed')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.success',
            'Clearing failed jobs for batch batch-1 was queued.',
        );

    Bus::assertDispatched(ClearBatchFailedJobsJob::class);
});

it('honors Horizon authorization when clearing batch failures', function (): void {
    Horizon::auth(static fn (): bool => false);

    delete('/horizon/batches/batch-1/failed')->assertForbidden();
});
