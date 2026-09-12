<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Audit\HorizonAuditEvent;
use DevactionLabs\Zenith\Schedule\Data\ScheduleRunData;
use DevactionLabs\Zenith\Schedule\DynamicSchedule;
use DevactionLabs\Zenith\Schedule\RunDynamicCron;
use DevactionLabs\Zenith\Schedule\ScheduleCatalog;
use DevactionLabs\Zenith\Schedule\SchedulePauseStatus;
use DevactionLabs\Zenith\Schedule\ScheduleRunHistory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Horizon;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    Schema::dropIfExists('zenith_dynamic_crons');
    app(CacheFactory::class)->store()->clear();
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

it('shows recorded run history for a scheduled event', function (): void {
    app(Schedule::class)->command('inspire')->hourly()->description('Inspire');
    $id = app(ScheduleCatalog::class)->events()[0]->id;

    app(ScheduleRunHistory::class)->record($id, new ScheduleRunData(
        status: 'failed',
        startedAt: 1_700_000_000.0,
        durationMs: 15.0,
        exitCode: 1,
        outputTail: 'boom',
    ));

    get('/horizon/schedule')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Schedule/Index')
            ->has('events.0.history', 1)
            ->where('events.0.history.0.status', 'failed')
            ->where('events.0.history.0.exitCode', 1)
            ->where('events.0.history.0.outputTail', 'boom'));
});

it('pauses and resumes the scheduler, and audits both changes', function (): void {
    post('/horizon/schedule/pause')
        ->assertRedirect()
        ->assertSessionHas('toast.success');

    expect(app(SchedulePauseStatus::class)->paused())->toBeTrue();

    delete('/horizon/schedule/pause')
        ->assertRedirect()
        ->assertSessionHas('toast.success');

    expect(app(SchedulePauseStatus::class)->paused())->toBeFalse();

    expect(HorizonAuditEvent::query()->where('route', 'zenith.schedule.pause.store')->count())
        ->toBeGreaterThan(0)
        ->and(HorizonAuditEvent::query()->where('route', 'zenith.schedule.pause.destroy')->count())
        ->toBeGreaterThan(0);
});

it('reflects the scheduler pause state on the schedule page', function (): void {
    Artisan::call('schedule:pause');

    get('/horizon/schedule')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('horizon.schedulePaused', true));
});

it('forbids pausing the scheduler when the manageSchedule gate is denied', function (): void {
    Gate::define('zenith.manageSchedule', static fn (): bool => false);

    post('/horizon/schedule/pause')->assertForbidden();
});

it('returns not found for an unknown schedule event', function (): void {
    post('/horizon/schedule/missing-event/run')->assertNotFound();
});

describe('dynamic crons', function (): void {
    beforeEach(function (): void {
        Artisan::call('migrate:refresh', [
            '--path' => dirname(__DIR__, 2).'/database/migrations/2026_08_30_020000_create_zenith_dynamic_crons_table.php',
            '--realpath' => true,
        ]);
    });

    afterEach(function (): void {
        Artisan::call('migrate:reset', [
            '--path' => dirname(__DIR__, 2).'/database/migrations/2026_08_30_020000_create_zenith_dynamic_crons_table.php',
            '--realpath' => true,
        ]);
    });

    it('lists dynamic crons as runtime-editable events', function (): void {
        $this->travelTo(Date::parse('2026-09-12 12:00:00'));
        $crons = app(DynamicSchedule::class);
        $nightly = $crons->create('nightly-report', '0 3 * * *', FetchWorkflowStep::class, timezone: 'America/Sao_Paulo');
        $paused = $crons->create('paused-sync', '* * * * *', FetchWorkflowStep::class);
        $crons->pause($paused->id);
        $nextRunAt = Date::parse('2026-09-13 06:00:00')->getTimestamp();

        get('/horizon/schedule')
            ->assertSuccessful()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Schedule/Index')
                ->has('events', 2)
                ->where('events.0.id', "dynamic-{$nightly->id}")
                ->where('events.0.expression', '0 3 * * *')
                ->where('events.0.description', 'nightly-report')
                ->where('events.0.command', FetchWorkflowStep::class)
                ->where('events.0.timezone', 'America/Sao_Paulo')
                ->where('events.0.nextRunAt', $nextRunAt)
                ->where('events.0.runtimeEditable', true)
                ->where('events.0.paused', false)
                ->where('events.1.description', 'paused-sync')
                ->where('events.1.timezone', 'UTC')
                ->where('events.1.nextRunAt', null)
                ->where('events.1.paused', true));
    });

    it('runs a dynamic cron on demand', function (): void {
        Bus::fake([RunDynamicCron::class]);
        $cron = app(DynamicSchedule::class)->create('often', '* * * * *', FetchWorkflowStep::class);

        post("/horizon/schedule/dynamic-{$cron->id}/run")
            ->assertRedirect()
            ->assertSessionHas('toast.success', 'Ran often.');

        Bus::assertDispatched(RunDynamicCron::class, fn (RunDynamicCron $job): bool => $job->cronId === $cron->id);
    });

    it('returns not found for an unknown dynamic cron', function (): void {
        post('/horizon/schedule/dynamic-999/run')->assertNotFound();
    });
});
