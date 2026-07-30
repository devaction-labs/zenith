<?php

declare(strict_types=1);

use Illuminate\Bus\BatchRepository;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Laravel\Horizon\Contracts\JobRepository;
use NckRtl\HorizonNewDawn\Batches\Actions\ClearBatchFailedJobs;
use NckRtl\HorizonNewDawn\FailedJobs\Actions\RemoveFailedJob;

use function NckRtl\HorizonNewDawn\Tests\Support\dashboardNeverReceives;
use function NckRtl\HorizonNewDawn\Tests\Support\dashboardReturnsFor;
use function NckRtl\HorizonNewDawn\Tests\Support\horizonBatch;
use function NckRtl\HorizonNewDawn\Tests\Support\mockDashboardContract;

it('clears each valid failed job belonging to the selected batch once', function (): void {
    $batch = horizonBatch(
        'batch-1',
        failedJobs: 3,
        failedJobIds: ['failed-1', 'failed-2', 'failed-1', '', '   '],
    );
    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batches, 'find', ['batch-1'], $batch);

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($jobs, 'deleteFailed', ['failed-1'], 1);
    dashboardReturnsFor($jobs, 'deleteFailed', ['failed-2'], 1);

    $failedJobs = mockDashboardContract(FailedJobProviderInterface::class);
    dashboardReturnsFor($failedJobs, 'forget', ['failed-1'], true);
    dashboardReturnsFor($failedJobs, 'forget', ['failed-2'], true);

    $clear = new ClearBatchFailedJobs(
        $batches,
        new RemoveFailedJob($jobs, $failedJobs),
    );

    expect($clear->handle('batch-1'))->toBe(2);
});

it('does nothing when the batch no longer exists', function (): void {
    $batches = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batches, 'find', ['missing'], null);
    $jobs = mockDashboardContract(JobRepository::class);
    $failedJobs = mockDashboardContract(FailedJobProviderInterface::class);
    dashboardNeverReceives($jobs, 'deleteFailed');
    dashboardNeverReceives($failedJobs, 'forget');

    $clear = new ClearBatchFailedJobs(
        $batches,
        new RemoveFailedJob($jobs, $failedJobs),
    );

    expect($clear->handle('missing'))->toBe(0);
});
