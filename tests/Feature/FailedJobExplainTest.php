<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Zenith;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
});

afterEach(function (): void {
    Zenith::explainFailureUsing(null);
});

it('explains a failure when an explainer is registered', function (): void {
    Zenith::explainFailureUsing(
        fn (string $jobClass, string $message): string => "Likely cause for {$jobClass}: {$message}",
    );

    $job = horizonJob(0, 'failed-1');
    $job->status = 'failed';
    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'findFailed', ['failed-1'], $job);
    app()->instance(JobRepository::class, $repository);

    post('/horizon/failed/failed-1/explain')
        ->assertRedirect()
        ->assertSessionHas('toast.success', fn (?string $message): bool => $message !== null && str_contains($message, 'Likely cause for'));
});

it('reports when no failure explainer is registered', function (): void {
    $job = horizonJob(0, 'failed-1');
    $job->status = 'failed';
    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'findFailed', ['failed-1'], $job);
    app()->instance(JobRepository::class, $repository);

    post('/horizon/failed/failed-1/explain')
        ->assertRedirect()
        ->assertSessionHas('toast.error', 'No failure explainer is registered.');
});

it('reports when the failed job can no longer be found', function (): void {
    Zenith::explainFailureUsing(fn (): string => 'explained');

    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'findFailed', ['missing'], null);
    app()->instance(JobRepository::class, $repository);

    post('/horizon/failed/missing/explain')
        ->assertRedirect()
        ->assertSessionHas('toast.error', 'The failed job could not be found.');
});
