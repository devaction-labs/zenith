<?php

declare(strict_types=1);

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Jobs\RetryFailedJob as HorizonRetryFailedJob;
use NckRtl\HorizonNewDawn\Batches\Actions\RetryQueueBatches;
use NckRtl\HorizonNewDawn\Batches\BatchesData;
use NckRtl\HorizonNewDawn\Batches\BatchJobsData;
use NckRtl\HorizonNewDawn\Batches\DatabaseBatchCapability;
use NckRtl\HorizonNewDawn\Batches\DatabaseBatchMetadataSynchronizer;
use NckRtl\HorizonNewDawn\Batches\DatabaseBatchQuery;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;
use NckRtl\HorizonNewDawn\FailedJobs\Actions\RetryFailedJob;
use NckRtl\HorizonNewDawn\FailedJobs\FailedJobRetryEligibility;
use NckRtl\HorizonNewDawn\Jobs\JobsData;

use function NckRtl\HorizonNewDawn\Tests\Support\bulkSnapshotRedis;
use function NckRtl\HorizonNewDawn\Tests\Support\dashboardNeverReceives;
use function NckRtl\HorizonNewDawn\Tests\Support\dashboardReturnsFor;
use function NckRtl\HorizonNewDawn\Tests\Support\dashboardReturnsUsing;
use function NckRtl\HorizonNewDawn\Tests\Support\horizonBatch;
use function NckRtl\HorizonNewDawn\Tests\Support\horizonJob;
use function NckRtl\HorizonNewDawn\Tests\Support\mockDashboardContract;

function queueBatchRetryAction(
    BatchRepository $batches,
    JobRepository $jobs,
    ?DatabaseBatchQuery $query = null,
): RetryQueueBatches {
    bulkSnapshotRedis();

    return new RetryQueueBatches(
        $batches,
        new BatchesData($batches, new BatchJobsData($jobs, new JobsData($jobs))),
        $jobs,
        new RetryFailedJob(
            app(Dispatcher::class),
            $jobs,
            new FailedJobRetryEligibility,
        ),
        app(BulkOperationSnapshot::class),
        $query,
    );
}

it('continues through short non-empty repository pages until the batch scan is exhausted', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);

    $batches = mockDashboardContract(BatchRepository::class);
    $failedBatch = horizonBatch('batch-001', failedJobs: 1, failedJobIds: ['failed-1']);
    $failedBatch->options['queue'] = 'reports';
    dashboardReturnsFor($batches, 'get', [50, null], [
        horizonBatch('batch-002', failedJobs: 0),
    ]);
    dashboardReturnsFor($batches, 'get', [50, 'batch-002'], [$failedBatch]);
    dashboardReturnsFor($batches, 'get', [50, 'batch-001'], []);

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsUsing($jobs, 'getJobs', fn (array $ids): Collection => new Collection(
        array_map(static fn (string $id): object => horizonJob(0, $id), $ids),
    ));

    $result = queueBatchRetryAction($batches, $jobs)->processChunk('reports');

    expect($result->complete)->toBeTrue()
        ->and($result->totalAffected)->toBe(1);
    Bus::assertDispatched(
        HorizonRetryFailedJob::class,
        fn (HorizonRetryFailedJob $job): bool => $job->id === 'failed-1',
    );
});

it('fails safely before retry side effects when the batch cursor does not advance', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);
    bulkSnapshotRedis();

    $batches = mockDashboardContract(BatchRepository::class);
    $failedBatch = horizonBatch('batch-010', failedJobs: 1, failedJobIds: ['failed-1']);
    $failedBatch->options['queue'] = 'reports';
    $invalidTail = horizonBatch('', failedJobs: 0);
    dashboardReturnsFor($batches, 'get', [50, null], [$failedBatch, $invalidTail]);

    $jobs = mockDashboardContract(JobRepository::class);

    expect(fn () => queueBatchRetryAction($batches, $jobs)->processChunk('reports'))
        ->toThrow(RuntimeException::class);

    Bus::assertNothingDispatched();
});

it('retries failed jobs from every retained batch page', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);
    bulkSnapshotRedis();

    $batches = mockDashboardContract(BatchRepository::class);
    $first = horizonBatch('batch-003', failedJobs: 1, failedJobIds: ['failed-3']);
    $first->options['queue'] = 'reports';
    $second = horizonBatch('batch-002', failedJobs: 1, failedJobIds: ['failed-2']);
    $second->options['queue'] = 'reports';
    $third = horizonBatch('batch-001', failedJobs: 1, failedJobIds: ['failed-1']);
    $third->options['queue'] = 'reports';
    dashboardReturnsFor($batches, 'get', [50, null], [$first]);
    dashboardReturnsFor($batches, 'get', [50, 'batch-003'], [$second]);
    dashboardReturnsFor($batches, 'get', [50, 'batch-002'], [$third]);
    dashboardReturnsFor($batches, 'get', [50, 'batch-001'], []);

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsUsing($jobs, 'getJobs', fn (array $ids): Collection => new Collection(
        array_map(static fn (string $id): object => horizonJob(0, $id), $ids),
    ));

    $result = queueBatchRetryAction($batches, $jobs)->processChunk('reports');

    expect($result->complete)->toBeTrue()
        ->and($result->totalAffected)->toBe(3);
    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, 3);
});

it('counts and retries duplicate failed job ids across batches only once', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);

    $first = horizonBatch('batch-002', failedJobs: 1, failedJobIds: ['failed-1']);
    $first->options['queue'] = 'reports';
    $second = horizonBatch('batch-001', failedJobs: 1, failedJobIds: ['failed-1']);
    $second->options['queue'] = 'reports';
    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batches, 'get', [50, null], [$first, $second]);
    dashboardReturnsFor($batches, 'get', [50, 'batch-001'], []);

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsUsing($jobs, 'getJobs', fn (array $ids): Collection => new Collection(
        array_map(static fn (string $id): object => horizonJob(0, $id), $ids),
    ));

    $result = queueBatchRetryAction($batches, $jobs)->processChunk('reports');

    expect($result->complete)->toBeTrue()
        ->and($result->totalAffected)->toBe(1);

    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, 1);
});

it('uses stored first-observed attribution instead of rescanning live batch defaults', function (): void {
    Bus::fake([HorizonRetryFailedJob::class]);
    bulkSnapshotRedis();
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', 'default');

    Schema::dropIfExists('horizon_new_dawn_batch_metadata');
    Schema::dropIfExists('job_batches');
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    $migration = require __DIR__.'/../../../database/migrations/2026_07_26_000000_create_horizon_new_dawn_batch_metadata_table.php';
    $migration->up();

    try {
        app('db')->table('job_batches')->insert([
            [
                'id' => 'batch-default',
                'name' => 'Default batch',
                'total_jobs' => 1,
                'pending_jobs' => 1,
                'failed_jobs' => 1,
                'failed_job_ids' => json_encode(['failed-default'], JSON_THROW_ON_ERROR),
                'options' => serialize([]),
                'cancelled_at' => null,
                'created_at' => 1,
                'finished_at' => null,
            ],
            [
                'id' => 'batch-priority',
                'name' => 'Priority batch',
                'total_jobs' => 1,
                'pending_jobs' => 1,
                'failed_jobs' => 1,
                'failed_job_ids' => json_encode(['failed-priority'], JSON_THROW_ON_ERROR),
                'options' => serialize(['queue' => 'priority']),
                'cancelled_at' => null,
                'created_at' => 2,
                'finished_at' => null,
            ],
        ]);

        $repository = new DatabaseBatchRepository(
            app(BatchFactory::class),
            app('db')->connection(),
            'job_batches',
        );
        $capability = new DatabaseBatchCapability($repository);
        $query = new DatabaseBatchQuery(
            $capability,
            new DatabaseBatchMetadataSynchronizer($capability, app('config')),
        );
        $fallbackRepository = mockDashboardContract(BatchRepository::class);
        dashboardNeverReceives($fallbackRepository, 'get');
        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturnsUsing($jobs, 'getJobs', fn (array $ids): Collection => new Collection(
            array_map(static fn (string $id): object => horizonJob(0, $id), $ids),
        ));

        $result = queueBatchRetryAction($fallbackRepository, $jobs, $query)
            ->processChunk('default');

        expect($result->complete)->toBeTrue()
            ->and($result->totalAffected)->toBe(1);

        Bus::assertDispatched(
            HorizonRetryFailedJob::class,
            fn (HorizonRetryFailedJob $job): bool => $job->id === 'failed-default',
        );
        Bus::assertNotDispatched(
            HorizonRetryFailedJob::class,
            fn (HorizonRetryFailedJob $job): bool => $job->id === 'failed-priority',
        );
    } finally {
        Schema::dropIfExists('horizon_new_dawn_batch_metadata');
        Schema::dropIfExists('job_batches');
    }
});
