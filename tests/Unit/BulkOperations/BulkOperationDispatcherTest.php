<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Bus;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\ClearFailedJobsJob;

use function NckRtl\HorizonNewDawn\Tests\Support\dashboardExpects;

it('dispatches bulk operations onto the configured asynchronous queue', function (): void {
    Bus::fake();
    config()->set('horizon-new-dawn.bulk_operations.connection', 'operations');
    config()->set('horizon-new-dawn.bulk_operations.queue', 'horizon-maintenance');

    $queue = Mockery::mock(Queue::class);
    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], value: $queue);
    app()->instance(QueueManager::class, $manager);

    app(BulkOperationDispatcher::class)->dispatch(new ClearFailedJobsJob);

    Bus::assertDispatched(
        ClearFailedJobsJob::class,
        fn (ClearFailedJobsJob $job): bool => $job->connection === 'operations'
            && $job->queue === 'horizon-maintenance',
    );
});

it('refuses to execute a bulk operation on the synchronous queue driver', function (): void {
    Bus::fake();
    config()->set('horizon-new-dawn.bulk_operations.connection', 'sync');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['sync'], value: new SyncQueue);
    app()->instance(QueueManager::class, $manager);

    expect(fn () => app(BulkOperationDispatcher::class)->dispatch(new ClearFailedJobsJob))
        ->toThrow(
            RuntimeException::class,
            'Horizon New Dawn bulk operations require an asynchronous queue connection.',
        );

    Bus::assertNothingDispatched();
});

it('refuses to execute a bulk operation on the null queue driver', function (): void {
    Bus::fake();
    config()->set('horizon-new-dawn.bulk_operations.connection', 'null');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['null'], value: new NullQueue);
    app()->instance(QueueManager::class, $manager);

    expect(fn () => app(BulkOperationDispatcher::class)->dispatch(new ClearFailedJobsJob))
        ->toThrow(
            RuntimeException::class,
            'Horizon New Dawn bulk operations require an asynchronous queue connection.',
        );

    Bus::assertNothingDispatched();
});
