<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Batches\BatchClearScope;
use DevactionLabs\Zenith\Batches\ClearableBatches;
use DevactionLabs\Zenith\Jobs\JobsData;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\horizonBatch;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

function clearableBatches(BatchRepository $batches, JobRepository $jobs): ClearableBatches
{
    return new ClearableBatches(
        $batches,
        $jobs,
        new JobsData($jobs),
    );
}

it('keeps later active retry batches out of clearable ids despite stale earlier pending hashes', function (): void {
    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batches, 'get', [1000, null], [
        horizonBatch('batch-2', pendingJobs: 1, failedJobs: 1),
        horizonBatch('batch-1', pendingJobs: 1, failedJobs: 1),
    ]);
    dashboardReturnsFor($batches, 'get', [1000, 'batch-1'], []);

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($jobs, 'countPending', [], 51);
    dashboardReturnsFor($jobs, 'getPending', ['-1'], new Collection);
    $active = horizonJob(50, 'pending-50');
    $payload = json_decode($active->payload, true, flags: JSON_THROW_ON_ERROR);
    data_set($payload, 'data.batchId', 'batch-1');
    $active->payload = json_encode($payload, JSON_THROW_ON_ERROR);
    dashboardReturnsFor($jobs, 'getPending', ['49'], new Collection([$active]));

    $ids = clearableBatches($batches, $jobs)->ids(BatchClearScope::Incomplete);

    expect($ids)->toBe(['batch-2']);
});

it('classifies every retained batch across repository pages', function (): void {
    config()->set('zenith.retained_batch_scan_limit', 1000);

    $calls = 0;
    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $batches,
        'get',
        static function (int $limit, ?string $before) use (&$calls): array {
            $calls++;

            expect($limit)->toBe(1000);

            return match ($before) {
                null => array_map(
                    static fn (int $index) => horizonBatch(
                        sprintf('batch-%04d', $index),
                        pendingJobs: 0,
                        finishedAt: 1_784_281_000 + $index,
                    ),
                    range(1001, 2),
                ),
                'batch-0002' => [
                    horizonBatch('batch-0001', pendingJobs: 0, finishedAt: 1_784_281_001),
                ],
                'batch-0001' => [],
                default => throw new LogicException("Unexpected batch cursor [{$before}]."),
            };
        },
    );

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'countPending', 0);

    $clearable = clearableBatches($batches, $jobs);
    $counts = $clearable->counts();

    expect($counts->toArray())->toBe([
        'incomplete' => 0,
        'complete' => 1001,
        'finished' => 1001,
        'cancelled' => 0,
        'available' => true,
        'completeScan' => true,
        'message' => null,
    ])->and($calls)->toBe(3)
        ->and($clearable->ids(BatchClearScope::Finished))->toHaveCount(1001)
        ->and($clearable->ids(BatchClearScope::Finished)[0])->toBe('batch-1001')
        ->and($clearable->ids(BatchClearScope::Finished)[1000])->toBe('batch-0001');
});

it('fails closed when the batch repository does not advance its cursor', function (): void {
    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $batches,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [horizonBatch('batch-2', pendingJobs: 0, finishedAt: 1_784_281_100)],
            'batch-2' => [horizonBatch('batch-2', pendingJobs: 0, finishedAt: 1_784_281_100)],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    $jobs = mockDashboardContract(JobRepository::class);
    $clearable = clearableBatches($batches, $jobs);

    expect($clearable->counts()->toArray())->toBe([
        'incomplete' => 0,
        'complete' => 0,
        'finished' => 0,
        'cancelled' => 0,
        'available' => false,
        'completeScan' => false,
        'message' => 'Batch clearing availability could not be verified.',
    ])->and(fn (): array => $clearable->ids(BatchClearScope::Finished))
        ->toThrow(RuntimeException::class, 'The batch repository did not advance its pagination cursor.');
});

it('verifies clearable candidates against every pending job page', function (): void {
    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $batches,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [
                horizonBatch('batch-2', pendingJobs: 1, failedJobs: 1),
                horizonBatch('batch-1', pendingJobs: 1, failedJobs: 1),
            ],
            'batch-1' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($jobs, 'countPending', [], 51);
    dashboardReturnsFor($jobs, 'getPending', ['-1'], new Collection);
    $active = horizonJob(50, 'pending-50');
    $payload = json_decode($active->payload, true, flags: JSON_THROW_ON_ERROR);
    data_set($payload, 'data.batchId', 'batch-1');
    $active->payload = json_encode($payload, JSON_THROW_ON_ERROR);
    dashboardReturnsFor($jobs, 'getPending', ['49'], new Collection([$active]));

    $ids = clearableBatches($batches, $jobs)->ids(BatchClearScope::Incomplete);

    expect($ids)->toBe(['batch-2']);
});
