<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Batches\BatchesData;
use DevactionLabs\Zenith\Batches\BatchJobsData;
use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\Dashboard\DashboardBatchSummary;
use DevactionLabs\Zenith\Dashboard\DashboardData;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Support\NavigationCounts;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\bindBrowserPageFixtures;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

it('guides operators to create the batch table and keeps batches navigation visible', function (): void {
    bindBrowserPageFixtures();
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
    $jobs = mockDashboardContract(JobRepository::class);
    $capability = new DatabaseBatchCapability($repository);

    app()->instance(BatchRepository::class, $repository);
    app()->instance(DatabaseBatchCapability::class, $capability);
    app()->instance(BatchesData::class, new BatchesData(
        $repository,
        new BatchJobsData($jobs, new JobsData($jobs)),
    ));
    app()->forgetInstance(DashboardBatchSummary::class);
    app()->forgetInstance(DashboardData::class);
    app()->forgetInstance(NavigationCounts::class);

    expect($capability->available())->toBeFalse();

    visit('/horizon')
        ->assertSee('Pending Jobs')
        ->assertSee('Failed Jobs')
        ->assertSee('Completed Jobs')
        ->assertDontSee('Batches in progress')
        ->assertSee('Batches')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues()
        ->click('Batches')
        ->assertPathIs('/horizon/batches')
        ->assertSee('Job batching is not configured')
        ->assertSee('php artisan make:queue-batches-table')
        ->assertSee('php artisan migrate')
        ->assertDontSee('Batches unavailable')
        ->assertSee('Batches')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});
