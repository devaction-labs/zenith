<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\TelemetryMetricsReader;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Horizon;

use function DevactionLabs\Zenith\Tests\Support\telemetryRedis;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    config()->set('zenith.telemetry.enabled', false);
});

it('renders the dashboard with live throughput disabled by default', function (): void {
    get('/horizon/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Dashboard')
            ->where('liveThroughput.available', false)
            ->where('liveThroughput.series', [])
            ->whereType('liveThroughput.message', 'string')
            ->where('liveMetricsGroupBy', 'state')
            ->where('liveMetricsWindow', '1h'));
});

it('reads the group-by and window selection from the query string', function (): void {
    get('/horizon/dashboard?groupBy=queue&window=24h')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('liveMetricsGroupBy', 'queue')
            ->where('liveMetricsWindow', '24h'));
});

it('falls back to the defaults for an unrecognized group-by or window', function (): void {
    get('/horizon/dashboard?groupBy=bogus&window=bogus')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('liveMetricsGroupBy', 'state')
            ->where('liveMetricsWindow', '1h'));
});

it('reports live throughput as available once the recorder is enabled', function (): void {
    config()->set('zenith.telemetry.enabled', true);

    ['redis' => $redis] = telemetryRedis();
    app()->instance(TelemetryMetricsReader::class, new TelemetryMetricsReader($redis));

    get('/horizon/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('liveThroughput.available', true)
            ->where('liveThroughput.message', null));
});
