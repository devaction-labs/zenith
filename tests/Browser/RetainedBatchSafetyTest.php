<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Batches\BatchesData;
use DevactionLabs\HorizonNewDawn\Batches\BatchJobsData;
use DevactionLabs\HorizonNewDawn\Jobs\JobsData;
use Illuminate\Bus\BatchRepository;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\HorizonNewDawn\Tests\Support\bindBrowserPageFixtures;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\HorizonNewDawn\Tests\Support\horizonBatch;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

it('shows the repository capability and allows verified clear controls after a complete multipage scan', function (): void {
    bindBrowserPageFixtures();

    $finished = horizonBatch(
        'batch-2',
        name: 'Retained import',
        pendingJobs: 0,
        finishedAt: 1_784_281_100,
    );
    $older = horizonBatch(
        'batch-1',
        name: 'Older finished',
        pendingJobs: 0,
        finishedAt: 1_784_281_000,
    );
    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [$finished],
            'batch-2' => [$older],
            'batch-1' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );
    $jobs = app(JobRepository::class);

    app()->instance(BatchRepository::class, $repository);
    app()->instance(BatchesData::class, new BatchesData(
        $repository,
        new BatchJobsData($jobs, new JobsData($jobs)),
    ));

    visit('/horizon/batches')
        ->assertSee('Exact batch queries unavailable')
        ->assertSee(
            'Exact retained batch queries require Laravel\'s database batch repository.',
        )
        ->assertDontSee('Retained history is incomplete')
        ->assertPresent('[aria-label="Batch actions"]')
        ->assertMissing('[aria-label^="Batch actions unavailable."]')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs()
        ->assertNoAccessibilityIssues();
});
