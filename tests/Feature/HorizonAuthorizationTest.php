<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Http\Middleware\Authenticate;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutMiddleware;

describe('Horizon authorization', function (): void {
    beforeEach(function (): void {
        withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    });

    it('uses Horizon authentication for every mutation route', function (): void {
        $preservedHorizonApiRoutes = [
            'horizon.monitoring.store',
            'horizon.monitoring-tag.destroy',
            'horizon.jobs-batches.retry',
            'horizon.retry-jobs.show',
        ];
        $mutationRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(
                fn ($route): bool => (
                    str_starts_with((string) $route->getName(), 'horizon-new-dawn.')
                    || in_array($route->getName(), $preservedHorizonApiRoutes, true)
                )
                    && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [],
            );

        expect($mutationRoutes)->not->toBeEmpty();

        $mutationRoutes->each(
            fn ($route) => expect($route->gatherMiddleware())
                ->toContain(Authenticate::class),
        );
    });

    it('denies package and preserved Horizon API mutations when Horizon rejects the request', function (): void {
        Horizon::auth(static fn (): bool => false);

        postJson('/horizon/monitoring', ['tag' => 'checkout'])->assertForbidden();
        postJson('/horizon/api/monitoring', ['tag' => 'checkout'])->assertForbidden();
        postJson('/horizon/api/batches/retry/batch-1')->assertForbidden();
        postJson('/horizon/api/jobs/retry/job-1')->assertForbidden();
    });
});
