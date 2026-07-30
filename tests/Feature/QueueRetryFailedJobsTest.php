<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Jobs\RetryFailedJob as HorizonRetryFailedJob;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\RetryAllFailedJobsJob;

use function NckRtl\HorizonNewDawn\Tests\Support\dashboardExpects;
use function NckRtl\HorizonNewDawn\Tests\Support\dashboardReturns;
use function NckRtl\HorizonNewDawn\Tests\Support\horizonJob;
use function NckRtl\HorizonNewDawn\Tests\Support\mockDashboardContract;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

/** @param 'never'|'once'|'twice'|'zeroOrMoreTimes' $times */
function bindQueueRetryAsyncBulkQueue(string $times = 'once'): void
{
    config()->set('horizon-new-dawn.bulk_operations.connection', 'operations');
    config()->set('horizon-new-dawn.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], times: $times, value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

function bindQueueRetrySyncBulkQueue(): void
{
    config()->set('horizon-new-dawn.bulk_operations.connection', 'sync');
    config()->set('horizon-new-dawn.bulk_operations.queue', null);

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['sync'], value: new SyncQueue);
    app()->instance(QueueManager::class, $manager);
}

/** @param array<int, object> $failedJobs */
function bindQueueRetryFailedJobs(array $failedJobs): void
{
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countFailed', count($failedJobs));
    dashboardReturns($jobs, 'getFailed', new Collection($failedJobs));
    app()->instance(JobRepository::class, $jobs);
}

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    config()->set('queue.connections.redis', ['driver' => 'redis']);
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

it('queues retrying failed jobs from one queue', function (): void {
    Bus::fake();
    bindQueueRetryAsyncBulkQueue();

    $matching = horizonJob(0, 'failed-batches');
    $matching->connection = 'redis';
    $matching->queue = 'batches';
    $other = horizonJob(1, 'failed-reports');
    $other->connection = 'redis';
    $other->queue = 'reports';
    bindQueueRetryFailedJobs([$matching, $other]);

    post('/horizon/queues/redis/batches/retry-failed')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Retrying failed jobs from batches was queued.');

    Bus::assertDispatched(
        RetryAllFailedJobsJob::class,
        fn (RetryAllFailedJobsJob $job): bool => $job->connectionName === 'redis'
            && $job->queueName === 'batches'
            && $job->operationId === null
            && $job->connection === 'operations'
            && $job->queue === 'horizon-maintenance',
    );
    Bus::assertDispatchedTimes(RetryAllFailedJobsJob::class, 1);
    Bus::assertNotDispatched(HorizonRetryFailedJob::class);
});

it('reports bulk queue configuration failures without retrying failed jobs', function (): void {
    Bus::fake();
    Exceptions::fake();
    bindQueueRetrySyncBulkQueue();

    bindQueueRetryFailedJobs([]);

    post('/horizon/queues/redis/batches/retry-failed')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'The bulk operation could not be queued. Check the application logs and try again.',
        );

    Bus::assertNothingDispatched();
    Exceptions::assertReportedCount(1);
});

it('queues queue-scoped retry all when failed jobs exceed the former ceiling', function (): void {
    Bus::fake();
    bindQueueRetryAsyncBulkQueue();

    $jobs = [];

    for ($index = 0; $index < 3; $index++) {
        $job = horizonJob($index, "failed-batches-{$index}");
        $job->connection = 'redis';
        $job->queue = 'batches';
        $jobs[] = $job;
    }

    bindQueueRetryFailedJobs($jobs);

    post('/horizon/queues/redis/batches/retry-failed')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Retrying failed jobs from batches was queued.');

    Bus::assertDispatched(
        RetryAllFailedJobsJob::class,
        fn (RetryAllFailedJobsJob $job): bool => $job->connectionName === 'redis'
            && $job->queueName === 'batches'
            && $job->operationId === null
            && $job->connection === 'operations'
            && $job->queue === 'horizon-maintenance',
    );
    Bus::assertDispatchedTimes(RetryAllFailedJobsJob::class, 1);
    Bus::assertNotDispatched(HorizonRetryFailedJob::class);
});
