<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Horizon;

use function Pest\Laravel\get;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
});

it('renders the executing page with a disabled telemetry message by default', function (): void {
    config()->set('zenith.telemetry.enabled', false);

    get('/horizon/executing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Executing/Index')
            ->where('executing.available', false)
            ->where('executing.jobs', [])
            ->whereType('executing.message', 'string'));
});

it('shares the executing feature flag through the horizon shell prop', function (): void {
    config()->set('zenith.telemetry.enabled', true);

    get('/horizon/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('horizon.telemetryEnabled', true));
});
