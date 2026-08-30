<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;
use DevactionLabs\HorizonNewDawn\FailedJobs\Actions\ClearFailedJobs;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\HorizonNewDawn\Tests\Support\bulkSnapshotRedis;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardExpects;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

it('clears claimed snapshot members without scanning live failed-job windows', function (): void {
    $redis = bulkSnapshotRedis();
    $redis->seedSortedSet('failed_jobs', [
        'failed-50' => -2.0,
        'failed-51' => -1.0,
    ]);

    $jobs = mockDashboardContract(JobRepository::class);
    $failedJobs = mockDashboardContract(FailedJobProviderInterface::class);

    dashboardExpects($jobs, 'deleteFailed', ['failed-50'], 'once', null, ordered: true);
    dashboardExpects($failedJobs, 'forget', ['failed-50'], 'once', null, ordered: true);
    dashboardExpects($jobs, 'deleteFailed', ['failed-51'], 'once', null, ordered: true);
    dashboardExpects($failedJobs, 'forget', ['failed-51'], 'once', null, ordered: true);

    $result = (new ClearFailedJobs(
        $jobs,
        $failedJobs,
        app(BulkOperationSnapshot::class),
    ))->processChunk();

    expect($result->complete)->toBeTrue()
        ->and($result->totalAffected)->toBe(2);
});
