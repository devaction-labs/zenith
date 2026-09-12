<?php

declare(strict_types=1);

use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\Zenith\BulkOperations\Jobs\ClearFailedJobsJob;
use DevactionLabs\Zenith\FailedJobs\Actions\ClearSelectedFailedJobs;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Bus;

use function DevactionLabs\Zenith\Tests\Support\bulkSnapshotRedis;
use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;

it('snapshots the selected failed job ids and dispatches a clear job', function (): void {
    Bus::fake();
    bulkSnapshotRedis();

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', [null], value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);

    $action = new ClearSelectedFailedJobs(
        app(BulkOperationSnapshot::class),
        app(BulkOperationDispatcher::class),
    );

    $action->handle(['failed-1', 'failed-2']);

    Bus::assertDispatchedTimes(ClearFailedJobsJob::class, 1);
    Bus::assertDispatched(
        ClearFailedJobsJob::class,
        function (ClearFailedJobsJob $job): bool {
            expect($job->operationId)->toMatch('/\A[a-f0-9]{32}\z/');

            $ids = app(BulkOperationSnapshot::class)->nextChunk((string) $job->operationId);
            expect($ids)->toEqualCanonicalizing(['failed-1', 'failed-2']);

            return true;
        },
    );
});
