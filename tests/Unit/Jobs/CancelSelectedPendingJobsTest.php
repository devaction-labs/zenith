<?php

declare(strict_types=1);

use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\Zenith\BulkOperations\Jobs\CancelPendingJobsJob;
use DevactionLabs\Zenith\Jobs\Actions\CancelSelectedPendingJobs;
use DevactionLabs\Zenith\Jobs\PendingJobCancellationScope;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Bus;

use function DevactionLabs\Zenith\Tests\Support\bulkSnapshotRedis;
use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;

it('snapshots the selected pending job ids and dispatches a scoped cancel job', function (): void {
    Bus::fake();
    bulkSnapshotRedis();

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', [null], value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);

    $action = new CancelSelectedPendingJobs(
        app(BulkOperationSnapshot::class),
        app(BulkOperationDispatcher::class),
    );

    $action->handle(['pending-1', 'pending-2']);

    Bus::assertDispatchedTimes(CancelPendingJobsJob::class, 1);
    Bus::assertDispatched(
        CancelPendingJobsJob::class,
        function (CancelPendingJobsJob $job): bool {
            expect($job->scope)->toBe(PendingJobCancellationScope::Pending)
                ->and($job->queueName)->toBeNull()
                ->and($job->operationId)->toMatch('/\A[a-f0-9]{32}\z/');

            $ids = app(BulkOperationSnapshot::class)->nextChunk((string) $job->operationId);
            expect($ids)->toEqualCanonicalizing(['pending-1', 'pending-2']);

            return true;
        },
    );
});
