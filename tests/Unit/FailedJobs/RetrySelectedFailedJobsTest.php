<?php

declare(strict_types=1);

use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryAllFailedJobsJob;
use DevactionLabs\Zenith\FailedJobs\Actions\RetrySelectedFailedJobs;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Bus;

use function DevactionLabs\Zenith\Tests\Support\bulkSnapshotRedis;
use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;

it('snapshots the selected failed job ids and dispatches an unscoped retry job', function (): void {
    Bus::fake();
    bulkSnapshotRedis();

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', [null], value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);

    $action = new RetrySelectedFailedJobs(
        app(BulkOperationSnapshot::class),
        app(BulkOperationDispatcher::class),
    );

    $action->handle(['failed-1', 'failed-2']);

    Bus::assertDispatchedTimes(RetryAllFailedJobsJob::class, 1);
    Bus::assertDispatched(
        RetryAllFailedJobsJob::class,
        function (RetryAllFailedJobsJob $job): bool {
            expect($job->connectionName)->toBeNull()
                ->and($job->queueName)->toBeNull()
                ->and($job->operationId)->toMatch('/\A[a-f0-9]{32}\z/');

            $ids = app(BulkOperationSnapshot::class)->nextChunk((string) $job->operationId);
            expect($ids)->toEqualCanonicalizing(['failed-1', 'failed-2']);

            return true;
        },
    );
});
