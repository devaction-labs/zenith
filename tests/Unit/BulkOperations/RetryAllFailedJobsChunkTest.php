<?php

declare(strict_types=1);

use DevactionLabs\Zenith\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryAllFailedJobsJob;
use DevactionLabs\Zenith\FailedJobs\Actions\RetryAllFailedJobs;
use DevactionLabs\Zenith\FailedJobs\Actions\RetryFailedJob;
use DevactionLabs\Zenith\FailedJobs\FailedJobRetryEligibility;
use DevactionLabs\Zenith\Tests\Support\BulkOperationSnapshotRedisConnection;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Jobs\RetryFailedJob as HorizonRetryFailedJob;

use function DevactionLabs\Zenith\Tests\Support\bulkSnapshotRedis;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

function chunkedRetryAction(
    JobRepository $repository,
    ?BulkOperationSnapshot $snapshots = null,
    ?RetryFailedJob $retry = null,
): RetryAllFailedJobs {
    return new RetryAllFailedJobs(
        $repository,
        $retry ?? new RetryFailedJob(
            app(Dispatcher::class),
            $repository,
            new FailedJobRetryEligibility,
        ),
        $snapshots ?? app(BulkOperationSnapshot::class),
    );
}

/**
 * @param  array<int, object{id: string}>  $jobs
 */
function bindFailedJobsForChunkRetry(array $jobs): JobRepository
{
    $redis = bulkSnapshotRedis();
    $members = [];

    foreach ($jobs as $index => $job) {
        $members[(string) $job->id] = (float) -$index;
    }

    $redis->seedSortedSet('failed_jobs', $members);

    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsUsing($repository, 'getJobs', function (array $ids) use ($jobs): Collection {
        $byId = [];

        foreach ($jobs as $job) {
            $byId[(string) $job->id] = $job;
        }

        return new Collection(array_values(array_filter(
            array_map(static fn (string $id): ?object => $byId[$id] ?? null, $ids),
        )));
    });

    return $repository;
}

it('processes at most the package chunk size and continues when targets remain', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);

    $jobs = array_map(
        static fn (int $index): object => horizonJob($index, "failed-{$index}"),
        range(0, BulkOperationSnapshot::CHUNK_SIZE + 9),
    );
    $repository = bindFailedJobsForChunkRetry($jobs);
    $action = chunkedRetryAction($repository);

    $first = $action->processChunk();

    expect($first->complete)->toBeFalse()
        ->and($first->operationId)->toMatch('/\A[a-f0-9]{32}\z/')
        ->and($first->totalAffected)->toBe(BulkOperationSnapshot::CHUNK_SIZE);

    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, BulkOperationSnapshot::CHUNK_SIZE);

    $second = $action->processChunk($first->operationId);

    expect($second->complete)->toBeTrue()
        ->and($second->operationId)->toBe($first->operationId)
        ->and($second->totalAffected)->toBe(BulkOperationSnapshot::CHUNK_SIZE + 10);

    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, BulkOperationSnapshot::CHUNK_SIZE + 10);
});

it('excludes failures retained after the operation boundary snapshot', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);

    $initial = [
        horizonJob(0, 'failed-1'),
        horizonJob(1, 'failed-2'),
    ];
    $repository = bindFailedJobsForChunkRetry($initial);
    $action = chunkedRetryAction($repository);

    $redis = app(RedisFactory::class)->connection('horizon');
    assert($redis instanceof BulkOperationSnapshotRedisConnection);

    $first = $action->processChunk();
    expect($first->complete)->toBeTrue();

    $redis->seedSortedSet('failed_jobs', [
        'failed-1' => -2.0,
        'failed-2' => -1.0,
        'failed-after' => -0.5,
    ]);

    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, 2);
    Bus::assertNotDispatched(
        HorizonRetryFailedJob::class,
        fn (HorizonRetryFailedJob $job): bool => $job->id === 'failed-after',
    );
});

it('does not skip later snapshot members when earlier live failures are removed mid-operation', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);

    $jobs = array_map(
        static fn (int $index): object => horizonJob($index, "failed-{$index}"),
        range(0, BulkOperationSnapshot::CHUNK_SIZE + 2),
    );
    $repository = bindFailedJobsForChunkRetry($jobs);
    $action = chunkedRetryAction($repository);

    $first = $action->processChunk();
    expect($first->complete)->toBeFalse();

    $redis = app(RedisFactory::class)->connection('horizon');
    assert($redis instanceof BulkOperationSnapshotRedisConnection);
    $redis->seedSortedSet('failed_jobs', [
        'failed-'.(BulkOperationSnapshot::CHUNK_SIZE) => -1.0,
        'failed-'.(BulkOperationSnapshot::CHUNK_SIZE + 1) => -2.0,
        'failed-'.(BulkOperationSnapshot::CHUNK_SIZE + 2) => -3.0,
    ]);

    $second = $action->processChunk($first->operationId);

    expect($second->complete)->toBeTrue()
        ->and($second->totalAffected)->toBe(BulkOperationSnapshot::CHUNK_SIZE + 3);

    foreach (range(0, BulkOperationSnapshot::CHUNK_SIZE + 2) as $index) {
        Bus::assertDispatched(
            HorizonRetryFailedJob::class,
            fn (HorizonRetryFailedJob $job): bool => $job->id === "failed-{$index}",
        );
    }
});

it('retries unacknowledged targets after a mutation throws mid-chunk', function (): void {
    $jobs = array_map(
        static fn (int $index): object => horizonJob($index, "failed-{$index}"),
        range(0, 4),
    );
    $repository = bindFailedJobsForChunkRetry($jobs);

    $dispatches = 0;
    $bus = mockDashboardContract(Dispatcher::class);
    dashboardReturnsUsing($bus, 'dispatch', function (mixed $command) use (&$dispatches): void {
        expect($command)->toBeInstanceOf(HorizonRetryFailedJob::class);
        $dispatches++;

        if ($command->id === 'failed-2' && $dispatches <= 3) {
            throw new RuntimeException('Redis write failed while scheduling retry.');
        }
    });

    $retry = new RetryFailedJob(
        $bus,
        $repository,
        new FailedJobRetryEligibility,
    );
    $action = chunkedRetryAction($repository, retry: $retry);

    expect(fn () => $action->processChunk())
        ->toThrow(RuntimeException::class, 'Redis write failed while scheduling retry.');

    $redis = app(RedisFactory::class)->connection('horizon');
    assert($redis instanceof BulkOperationSnapshotRedisConnection);

    $operationKeys = array_values(array_filter(
        array_keys($redis->hashes),
        static fn (string $key): bool => str_ends_with($key, ':meta'),
    ));
    expect($operationKeys)->toHaveCount(1);
    $operationId = substr($operationKeys[0], strlen("\x1fzenith:v1:bulk-op:"), 32);

    // Score order peels highest-magnitude negative first: failed-4, failed-3,
    // failed-2 throws after two successes. Resume must still process failed-2+.
    $resume = $action->processChunk($operationId);

    expect($resume->complete)->toBeTrue()
        ->and($resume->totalAffected)->toBe(5)
        ->and($dispatches)->toBe(6);
});

it('does not reschedule after a crash between successful dispatch and acknowledge', function (): void {
    $job = horizonJob(0, 'failed-0');
    $repository = bindFailedJobsForChunkRetry([$job]);

    $dispatches = 0;
    $bus = mockDashboardContract(Dispatcher::class);
    dashboardReturnsUsing($bus, 'dispatch', function (mixed $command) use (&$dispatches, $job): void {
        expect($command)->toBeInstanceOf(HorizonRetryFailedJob::class)
            ->and($command->id)->toBe('failed-0');

        $dispatches++;
        // Establish the retry reference so bulk eligibility blocks a second schedule.
        $job->retried_by = json_encode([
            ['id' => 'retry-child', 'status' => 'pending'],
        ], JSON_THROW_ON_ERROR);
    });

    $redis = app(RedisFactory::class)->connection('horizon');
    assert($redis instanceof BulkOperationSnapshotRedisConnection);
    $redis->failZremRemaining = 1;

    $action = chunkedRetryAction(
        $repository,
        retry: new RetryFailedJob(
            $bus,
            $repository,
            new FailedJobRetryEligibility,
        ),
    );

    expect(fn () => $action->processChunk())
        ->toThrow(RuntimeException::class, 'Snapshot acknowledge ZREM failed.');

    expect($dispatches)->toBe(1);

    $operationKeys = array_values(array_filter(
        array_keys($redis->hashes),
        static fn (string $key): bool => str_ends_with($key, ':meta'),
    ));
    expect($operationKeys)->toHaveCount(1);
    $operationId = substr($operationKeys[0], strlen("\x1fzenith:v1:bulk-op:"), 32);

    expect(app(BulkOperationSnapshot::class)->hasMore($operationId))->toBeTrue()
        ->and(app(BulkOperationSnapshot::class)->nextChunk($operationId))->toBe(['failed-0']);

    $resume = $action->processChunk($operationId);

    expect($resume->complete)->toBeTrue()
        ->and($resume->totalAffected)->toBe(1)
        ->and($dispatches)->toBe(1)
        ->and($redis->exists("\x1fzenith:v1:bulk-op:{$operationId}:meta"))->toBe(0)
        ->and($redis->exists("\x1fzenith:v1:bulk-op:{$operationId}:targets"))->toBe(0);
});

it('retries only failures from the requested connection and queue while chunking', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);

    $matching = horizonJob(0, 'failed-batches');
    $matching->queue = 'batches';
    $matching->connection = 'redis';

    $otherQueue = horizonJob(1, 'failed-reports');
    $otherQueue->queue = 'reports';
    $otherQueue->connection = 'redis';

    $otherConnection = horizonJob(2, 'failed-sqs-batches');
    $otherConnection->connection = 'sqs';
    $otherConnection->queue = 'batches';

    $repository = bindFailedJobsForChunkRetry([$matching, $otherQueue, $otherConnection]);
    $result = chunkedRetryAction($repository)->processChunk(null, 'redis', 'batches');

    expect($result->complete)->toBeTrue()
        ->and($result->totalAffected)->toBe(1);

    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, 1);
    Bus::assertDispatched(
        HorizonRetryFailedJob::class,
        fn (HorizonRetryFailedJob $job): bool => $job->id === 'failed-batches',
    );
});

it('dispatches a continuation job with only bounded scalar state on the bulk queue', function (): void {
    config()->set('zenith.bulk_operations.connection', 'operations');
    config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

    Bus::fake();

    $jobs = array_map(
        static fn (int $index): object => horizonJob($index, "failed-{$index}"),
        range(0, BulkOperationSnapshot::CHUNK_SIZE + 1),
    );
    $repository = bindFailedJobsForChunkRetry($jobs);
    app()->instance(JobRepository::class, $repository);
    app()->instance(RetryAllFailedJobs::class, chunkedRetryAction($repository));

    Log::shouldReceive('info')->never();

    (new RetryAllFailedJobsJob)->handle(app(RetryAllFailedJobs::class));

    Bus::assertDispatched(
        RetryAllFailedJobsJob::class,
        function (RetryAllFailedJobsJob $job): bool {
            $serialized = serialize($job);

            expect($job->operationId)->toMatch('/\A[a-f0-9]{32}\z/')
                ->and($job->connectionName)->toBeNull()
                ->and($job->queueName)->toBeNull()
                ->and($job->connection)->toBe('operations')
                ->and($job->queue)->toBe('horizon-maintenance')
                ->and($serialized)->not->toContain('failed-0')
                ->and($serialized)->not->toContain('failed-1');

            return true;
        },
    );
    Bus::assertDispatchedTimes(RetryAllFailedJobsJob::class, 1);
    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, BulkOperationSnapshot::CHUNK_SIZE);
});

it('bulk retries the failed leaf instead of branching again from its parent', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);

    $parent = horizonJob(0, 'parent');
    $parent->retried_by = json_encode([
        ['id' => 'retry-leaf', 'status' => 'failed'],
    ], JSON_THROW_ON_ERROR);
    $retryLeaf = horizonJob(1, 'retry-leaf');
    $payload = json_decode($retryLeaf->payload, true, flags: JSON_THROW_ON_ERROR);
    $retryLeaf->payload = json_encode([...$payload, 'retry_of' => 'parent'], JSON_THROW_ON_ERROR);

    $repository = bindFailedJobsForChunkRetry([$parent, $retryLeaf]);
    $result = chunkedRetryAction($repository)->processChunk();

    expect($result->complete)->toBeTrue()
        ->and($result->totalAffected)->toBe(1);

    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, 1);
    Bus::assertDispatched(
        HorizonRetryFailedJob::class,
        fn (HorizonRetryFailedJob $job): bool => $job->id === 'retry-leaf',
    );
});
