<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\HorizonNewDawn\FailedJobs\Actions\RetryAllFailedJobs;
use DevactionLabs\HorizonNewDawn\FailedJobs\Actions\RetryFailedJob;
use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobRetryLock;
use DevactionLabs\HorizonNewDawn\Jobs\Actions\CancelPendingJobs;
use DevactionLabs\HorizonNewDawn\Jobs\Actions\ClearPendingJobs;
use DevactionLabs\HorizonNewDawn\Queues\ClearsQueueMetadata;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Queue\QueueManager;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardExpects;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturns;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardThrowsFor;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

it('resolves bulk actions with their production snapshot dependencies', function (): void {
    $cancel = app(CancelPendingJobs::class);
    $retry = app(RetryAllFailedJobs::class);
    $retryOne = app(RetryFailedJob::class);

    expect((new ReflectionProperty($cancel, 'snapshots'))->getValue($cancel))
        ->toBeInstanceOf(BulkOperationSnapshot::class)
        ->and((new ReflectionProperty($retry, 'snapshots'))->getValue($retry))
        ->toBeInstanceOf(BulkOperationSnapshot::class)
        ->and((new ReflectionProperty($retryOne, 'lock'))->getValue($retryOne))
        ->toBeInstanceOf(FailedJobRetryLock::class);
});

it('never clears the queue running bulk operations', function (
    ?string $bulkConnection,
    ?string $bulkQueue,
): void {
    config()->set('horizon-new-dawn.bulk_operations.connection', $bulkConnection);
    config()->set('horizon-new-dawn.bulk_operations.queue', $bulkQueue);
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', 'horizon-maintenance');

    $supervisors = mockDashboardContract(SupervisorRepository::class);
    dashboardReturns($supervisors, 'all', [
        (object) ['processes' => ['redis:reports,horizon-maintenance' => 1]],
    ]);
    app()->instance(SupervisorRepository::class, $supervisors);

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countPending', 0);
    app()->instance(JobRepository::class, $jobs);

    $queue = mockDashboardContract(ClearableQueue::class);
    dashboardExpects($queue, 'clear', ['reports'], value: 3);
    dashboardExpects($queue, 'clear', ['horizon-maintenance'], times: 'never');

    $queues = Mockery::mock(QueueManager::class);
    dashboardReturns($queues, 'connection', $queue);
    app()->instance(QueueManager::class, $queues);

    $metadata = mockDashboardContract(ClearsQueueMetadata::class);
    dashboardExpects($metadata, 'purgePending', ['redis', 'reports'], value: 3);
    dashboardExpects(
        $metadata,
        'purgePending',
        ['redis', 'horizon-maintenance'],
        times: 'never',
    );
    app()->instance(ClearsQueueMetadata::class, $metadata);

    $result = app(ClearPendingJobs::class)->handle();

    expect($result->cleared)->toBe(3)
        ->and($result->failedTargets)->toBe([]);
})->with([
    'explicit bulk target' => ['redis', 'horizon-maintenance'],
    'connection and queue defaults' => [null, null],
]);

it('fails closed when retained pending target discovery cannot complete', function (): void {
    $supervisors = mockDashboardContract(SupervisorRepository::class);
    dashboardReturns($supervisors, 'all', [
        (object) ['processes' => ['redis:reports' => 1]],
    ]);
    app()->instance(SupervisorRepository::class, $supervisors);

    $scanFailure = new RuntimeException('Pending job discovery failed.');
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countPending', 1);
    dashboardThrowsFor($jobs, 'getPending', ['-1'], $scanFailure);
    app()->instance(JobRepository::class, $jobs);

    $queues = Mockery::mock(QueueManager::class);
    $queues->shouldNotReceive('connection');
    app()->instance(QueueManager::class, $queues);

    expect(fn () => app(ClearPendingJobs::class)->handle())
        ->toThrow(RuntimeException::class, 'Pending job discovery failed.');
});
