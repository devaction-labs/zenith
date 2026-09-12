<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Dashboard\DashboardBatchSummary;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Support\Facades\Schema;

use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\horizonBatch;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

it('counts and previews active batches from repository pages', function (): void {
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    config()->set('zenith.poll_interval', 0);

    $activeNewest = horizonBatch('batch-6', name: 'Newest', totalJobs: 4, pendingJobs: 2);
    $failedOnly = horizonBatch('batch-5', totalJobs: 4, pendingJobs: 1, failedJobs: 1);
    $cancelled = horizonBatch('batch-4', totalJobs: 4, pendingJobs: 3, cancelledAt: 1_784_281_100);
    $activeOlder = horizonBatch('batch-3', name: '', totalJobs: 4, pendingJobs: 3, failedJobs: 1);
    $finished = horizonBatch('batch-2', totalJobs: 4, pendingJobs: 0, finishedAt: 1_784_281_200);

    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $batches,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [$activeNewest, $failedOnly],
            'batch-5' => [$cancelled, $activeOlder, $finished],
            'batch-2' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    app()->instance(BatchRepository::class, $batches);

    expect(app(DashboardBatchSummary::class)->get()->toArray())->toBe([
        'batchesAvailable' => true,
        'active' => 2,
        'previews' => [
            ['id' => 'batch-6', 'name' => 'Newest', 'progress' => 50],
            ['id' => 'batch-3', 'name' => 'batch-3', 'progress' => 25],
        ],
    ]);
});

it('counts every active batch across repository pages', function (): void {
    config()->set('queue.batching.database', null);
    config()->set('zenith.poll_interval', 0);

    $activeNewest = horizonBatch('batch-3', name: 'Newest', totalJobs: 4, pendingJobs: 2);
    $finished = horizonBatch('batch-2', totalJobs: 4, pendingJobs: 0, finishedAt: 1_784_281_200);
    $activeOlder = horizonBatch('batch-1', name: 'Older', totalJobs: 4, pendingJobs: 3);
    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $batches,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [$activeNewest, $finished],
            'batch-2' => [$activeOlder],
            'batch-1' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );
    app()->instance(BatchRepository::class, $batches);

    expect(app(DashboardBatchSummary::class)->get()->toArray())->toBe([
        'batchesAvailable' => true,
        'active' => 2,
        'previews' => [
            ['id' => 'batch-3', 'name' => 'Newest', 'progress' => 50],
            ['id' => 'batch-1', 'name' => 'Older', 'progress' => 25],
        ],
    ]);
});

it('reports batches unavailable without scanning when the database batch table is missing', function (): void {
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    config()->set('zenith.poll_interval', 0);
    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');

    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        app('db')->connection(),
        'job_batches',
    );
    app()->instance(BatchRepository::class, $repository);

    expect(app(DashboardBatchSummary::class)->get()->toArray())->toBe([
        'batchesAvailable' => false,
        'active' => null,
        'previews' => [],
    ]);
});
