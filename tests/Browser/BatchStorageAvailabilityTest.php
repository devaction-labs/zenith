<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Batches\BatchesData;
use DevactionLabs\HorizonNewDawn\Batches\BatchJobsData;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchCapability;
use DevactionLabs\HorizonNewDawn\Dashboard\DashboardBatchSummary;
use DevactionLabs\HorizonNewDawn\Dashboard\DashboardData;
use DevactionLabs\HorizonNewDawn\Jobs\JobsData;
use DevactionLabs\HorizonNewDawn\Support\NavigationCounts;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\HorizonNewDawn\Tests\Support\bindBrowserPageFixtures;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

it('guides operators to create the batch table and keeps batches navigation visible', function (): void {
    bindBrowserPageFixtures();
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    config()->set('horizon-new-dawn.poll_interval', 0);
    Schema::dropIfExists('horizon_new_dawn_batch_metadata');
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
