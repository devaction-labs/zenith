<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Schedule\ScheduleCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Horizon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

it('lists scheduled events with next run and overlap flags', function (): void {
    app(Schedule::class)
        ->command('inspire')
        ->hourly()
        ->timezone('UTC')
        ->withoutOverlapping()
        ->description('Inspire the team');

    get('/horizon/schedule')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Schedule/Index')
            ->where('meta.activeNavigation', 'schedule')
            ->has('events', 1)
            ->where('events.0.expression', '0 * * * *')
            ->where('events.0.description', 'Inspire the team')
            ->where('events.0.timezone', 'UTC')
            ->where('events.0.withoutOverlapping', true)
            ->where('events.0.runtimeEditable', false)
            ->where('canRun', true)
            ->has('events.0.id')
            ->has('events.0.nextRunAt'));
});

it('runs a scheduled event on demand', function (): void {
    $ran = false;

    app(Schedule::class)->call(function () use (&$ran): void {
        $ran = true;
    })->everyMinute()->description('Probe schedule');

    $id = app(ScheduleCatalog::class)->events()[0]->id;

    post("/horizon/schedule/{$id}/run")
        ->assertRedirect()
        ->assertSessionHas('toast.success');

    expect($ran)->toBeTrue();
});

it('forbids running a schedule when the manageSchedule gate is denied', function (): void {
    Gate::define('zenith.manageSchedule', static fn (): bool => false);

    app(Schedule::class)->command('inspire')->hourly()->description('Inspire');

    $id = app(ScheduleCatalog::class)->events()[0]->id;

    post("/horizon/schedule/{$id}/run")->assertForbidden();
});

it('returns not found for an unknown schedule event', function (): void {
    post('/horizon/schedule/missing-event/run')->assertNotFound();
});
