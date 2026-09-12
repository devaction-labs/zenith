<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Audit\HorizonAuditEvent;
use DevactionLabs\Zenith\Schedule\DynamicCron;
use DevactionLabs\Zenith\Schedule\DynamicSchedule;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Horizon;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutMiddleware;

function migrateDynamicCronsTable(string $command): void
{
    Artisan::call($command, [
        '--path' => dirname(__DIR__, 2).'/database/migrations/2026_08_30_020000_create_zenith_dynamic_crons_table.php',
        '--realpath' => true,
    ]);
}

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    migrateDynamicCronsTable('migrate:refresh');
    config()->set('zenith.dynamic_cron_allowed_classes', [FetchWorkflowStep::class]);
});

afterEach(function (): void {
    migrateDynamicCronsTable('migrate:reset');
    Horizon::auth(static fn (): bool => true);
});

it('shares the configured allowlist with the Schedule page', function (): void {
    get('/horizon/schedule')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Schedule/Index')
            ->where('dynamicCronAllowedClasses', [FetchWorkflowStep::class]));
});

it('creates a dynamic cron and audits the mutation', function (): void {
    post('/horizon/schedule/dynamic-crons', [
        'name' => 'nightly-report',
        'expression' => '0 3 * * *',
        'job_class' => FetchWorkflowStep::class,
        'payload' => '{"seed":[1,2]}',
        'timezone' => 'America/Sao_Paulo',
    ])
        ->assertRedirect()
        ->assertSessionHas('toast.success');

    $cron = DynamicCron::query()->where('name', 'nightly-report')->firstOrFail();

    expect($cron->expression)->toBe('0 3 * * *')
        ->and($cron->job_class)->toBe(FetchWorkflowStep::class)
        ->and($cron->payload)->toBe(['seed' => [1, 2]])
        ->and($cron->timezone)->toBe('America/Sao_Paulo')
        ->and(HorizonAuditEvent::query()->where('route', 'zenith.schedule.dynamic-crons.store')->count())
        ->toBeGreaterThan(0);
});

it('rejects creating a dynamic cron with an invalid cron expression', function (): void {
    post('/horizon/schedule/dynamic-crons', [
        'name' => 'broken',
        'expression' => 'not a cron',
        'job_class' => FetchWorkflowStep::class,
    ])->assertInvalid(['expression']);

    expect(DynamicCron::query()->where('name', 'broken')->exists())->toBeFalse();
});

it('rejects creating a dynamic cron with an invalid timezone', function (): void {
    post('/horizon/schedule/dynamic-crons', [
        'name' => 'broken',
        'expression' => '* * * * *',
        'job_class' => FetchWorkflowStep::class,
        'timezone' => 'Mars/Olympus',
    ])->assertInvalid(['timezone']);
});

it('rejects creating a dynamic cron for a job class outside the allowlist', function (): void {
    post('/horizon/schedule/dynamic-crons', [
        'name' => 'broken',
        'expression' => '* * * * *',
        'job_class' => ProcessWorkflowStep::class,
    ])->assertInvalid(['job_class']);

    expect(DynamicCron::query()->where('name', 'broken')->exists())->toBeFalse();
});

it('rejects creating a dynamic cron with a duplicate name', function (): void {
    app(DynamicSchedule::class)->create('taken', '* * * * *', FetchWorkflowStep::class);

    post('/horizon/schedule/dynamic-crons', [
        'name' => 'taken',
        'expression' => '* * * * *',
        'job_class' => FetchWorkflowStep::class,
    ])->assertInvalid(['name']);
});

it('rejects creating malformed JSON as the payload', function (): void {
    post('/horizon/schedule/dynamic-crons', [
        'name' => 'broken-payload',
        'expression' => '* * * * *',
        'job_class' => FetchWorkflowStep::class,
        'payload' => '{not json}',
    ])->assertInvalid(['payload']);
});

it('forbids creating a dynamic cron when the manageSchedule gate is denied', function (): void {
    Gate::define('zenith.manageSchedule', static fn (): bool => false);

    post('/horizon/schedule/dynamic-crons', [
        'name' => 'nightly',
        'expression' => '0 3 * * *',
        'job_class' => FetchWorkflowStep::class,
    ])->assertForbidden();
});

it('updates a dynamic cron and audits the mutation', function (): void {
    $cron = app(DynamicSchedule::class)->create('often', '* * * * *', FetchWorkflowStep::class);

    put("/horizon/schedule/dynamic-crons/{$cron->id}", [
        'name' => 'often-renamed',
        'expression' => '*/5 * * * *',
        'job_class' => FetchWorkflowStep::class,
        'timezone' => 'UTC',
    ])
        ->assertRedirect()
        ->assertSessionHas('toast.success');

    expect($cron->refresh()->name)->toBe('often-renamed')
        ->and($cron->expression)->toBe('*/5 * * * *')
        ->and(HorizonAuditEvent::query()->where('route', 'zenith.schedule.dynamic-crons.update')->count())
        ->toBeGreaterThan(0);
});

it('allows renaming a cron to keep its own current name', function (): void {
    $cron = app(DynamicSchedule::class)->create('often', '* * * * *', FetchWorkflowStep::class);

    put("/horizon/schedule/dynamic-crons/{$cron->id}", [
        'name' => 'often',
        'expression' => '*/5 * * * *',
        'job_class' => FetchWorkflowStep::class,
    ])->assertSessionHasNoErrors();
});

it('returns not found when updating an unknown dynamic cron', function (): void {
    put('/horizon/schedule/dynamic-crons/999', [
        'name' => 'ghost',
        'expression' => '* * * * *',
        'job_class' => FetchWorkflowStep::class,
    ])->assertNotFound();
});

it('deletes a dynamic cron and audits the mutation', function (): void {
    $cron = app(DynamicSchedule::class)->create('disposable', '* * * * *', FetchWorkflowStep::class);

    delete("/horizon/schedule/dynamic-crons/{$cron->id}")
        ->assertRedirect()
        ->assertSessionHas('toast.success');

    expect(DynamicCron::query()->find($cron->id))->toBeNull()
        ->and(HorizonAuditEvent::query()->where('route', 'zenith.schedule.dynamic-crons.destroy')->count())
        ->toBeGreaterThan(0);
});

it('returns not found when deleting an unknown dynamic cron', function (): void {
    delete('/horizon/schedule/dynamic-crons/999')->assertNotFound();
});

it('pauses and resumes a dynamic cron and audits both changes', function (): void {
    $cron = app(DynamicSchedule::class)->create('often', '* * * * *', FetchWorkflowStep::class);

    post("/horizon/schedule/dynamic-crons/{$cron->id}/pause")
        ->assertRedirect()
        ->assertSessionHas('toast.success');

    expect($cron->refresh()->paused)->toBeTrue();

    delete("/horizon/schedule/dynamic-crons/{$cron->id}/pause")
        ->assertRedirect()
        ->assertSessionHas('toast.success');

    expect($cron->refresh()->paused)->toBeFalse()
        ->and(HorizonAuditEvent::query()->where('route', 'zenith.schedule.dynamic-crons.pause.store')->count())
        ->toBeGreaterThan(0)
        ->and(HorizonAuditEvent::query()->where('route', 'zenith.schedule.dynamic-crons.pause.destroy')->count())
        ->toBeGreaterThan(0);
});

it('returns not found when pausing an unknown dynamic cron', function (): void {
    post('/horizon/schedule/dynamic-crons/999/pause')->assertNotFound();
});

it('forbids deleting a dynamic cron when the manageSchedule gate is denied', function (): void {
    $cron = app(DynamicSchedule::class)->create('protected', '* * * * *', FetchWorkflowStep::class);
    Gate::define('zenith.manageSchedule', static fn (): bool => false);

    delete("/horizon/schedule/dynamic-crons/{$cron->id}")->assertForbidden();

    expect(DynamicCron::query()->find($cron->id))->not->toBeNull();
});
