<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Schedule\DynamicSchedule;
use DevactionLabs\Zenith\Schedule\RunDynamicCron;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DynamicCronProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    public static array $regions = [];

    public function __construct(public string $region = 'default') {}

    public function handle(): void
    {
        self::$regions[] = $this->region;
    }
}

function scheduledZenithEvent(string $description): Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if ($event->description === $description) {
            return $event;
        }
    }

    throw new UnexpectedValueException("Scheduled event [{$description}] is not registered.");
}

function runDynamicCronMigration(string $command): void
{
    Artisan::call($command, [
        '--path' => dirname(__DIR__, 3).'/database/migrations/2026_08_30_020000_create_zenith_dynamic_crons_table.php',
        '--realpath' => true,
    ]);
}

beforeEach(function (): void {
    DynamicCronProbeJob::$regions = [];

    Schema::dropIfExists('zenith_dynamic_crons');
    runDynamicCronMigration('migrate:refresh');
    config()->set('zenith.dynamic_cron_allowed_classes', [DynamicCronProbeJob::class]);
});

afterEach(function (): void {
    runDynamicCronMigration('migrate:reset');
});

it('adds the timezone and claim columns to the dynamic cron table', function (): void {
    expect(Schema::hasColumns('zenith_dynamic_crons', ['timezone', 'last_ran_at']))->toBeTrue();
});

it('dispatches a due row again in the next minute', function (): void {
    $this->travelTo(Date::parse('2026-09-12 12:00:10'));
    Bus::fake([RunDynamicCron::class]);
    $cron = app(DynamicSchedule::class)->create('often', '* * * * *', DynamicCronProbeJob::class);

    expect(app(DynamicSchedule::class)->tick())->toBe(1)
        ->and(app(DynamicSchedule::class)->tick())->toBe(0);

    $this->travel(1)->minute();

    expect(app(DynamicSchedule::class)->tick())->toBe(1);
    Bus::assertDispatchedTimes(RunDynamicCron::class, 2);
    Bus::assertDispatched(RunDynamicCron::class, fn (RunDynamicCron $job): bool => $job->cronId === $cron->id);
});

it('skips rows that are not due', function (): void {
    $this->travelTo(Date::parse('2026-09-12 12:00:00'));
    Bus::fake([RunDynamicCron::class]);
    app(DynamicSchedule::class)->create('nightly', '0 3 * * *', DynamicCronProbeJob::class);

    expect(app(DynamicSchedule::class)->tick())->toBe(0);
    Bus::assertNothingDispatched();
});

it('evaluates each row in its own timezone', function (): void {
    $this->travelTo(Date::parse('2026-09-12 06:00:00'));
    Bus::fake([RunDynamicCron::class]);
    $crons = app(DynamicSchedule::class);
    $saoPaulo = $crons->create('sao-paulo', '0 3 * * *', DynamicCronProbeJob::class, timezone: 'America/Sao_Paulo');
    $crons->create('utc', '0 3 * * *', DynamicCronProbeJob::class);

    expect($crons->tick())->toBe(1);
    Bus::assertDispatched(RunDynamicCron::class, fn (RunDynamicCron $job): bool => $job->cronId === $saoPaulo->id);
});

it('claims a row for only one scheduler host per minute', function (): void {
    Bus::fake([RunDynamicCron::class]);
    app(DynamicSchedule::class)->create('often', '* * * * *', DynamicCronProbeJob::class);
    $otherHostTicked = false;

    DB::listen(function (QueryExecuted $query) use (&$otherHostTicked): void {
        if ($otherHostTicked || ! str_starts_with($query->sql, 'select * from "zenith_dynamic_crons"')) {
            return;
        }

        $otherHostTicked = true;

        expect(app(DynamicSchedule::class)->tick())->toBe(1);
    });

    expect(app(DynamicSchedule::class)->tick())->toBe(0)
        ->and($otherHostTicked)->toBeTrue();
    Bus::assertDispatchedTimes(RunDynamicCron::class, 1);
});

it('skips rows with an invalid expression or timezone without blocking others', function (): void {
    Bus::fake([RunDynamicCron::class]);
    DB::table('zenith_dynamic_crons')->insert([
        'name' => 'broken-expression',
        'expression' => 'not a cron',
        'job_class' => DynamicCronProbeJob::class,
        'paused' => false,
    ]);
    DB::table('zenith_dynamic_crons')->insert([
        'name' => 'broken-timezone',
        'expression' => '* * * * *',
        'job_class' => DynamicCronProbeJob::class,
        'paused' => false,
        'timezone' => 'Mars/Olympus',
    ]);
    app(DynamicSchedule::class)->create('valid', '* * * * *', DynamicCronProbeJob::class);

    expect(app(DynamicSchedule::class)->tick())->toBe(1);
});

it('rejects an invalid cron expression or timezone', function (): void {
    $crons = app(DynamicSchedule::class);

    expect(fn (): mixed => $crons->create('broken', 'not a cron', DynamicCronProbeJob::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => $crons->create('broken', '* * * * *', DynamicCronProbeJob::class, timezone: 'Mars/Olympus'))
        ->toThrow(InvalidArgumentException::class);
});

it('dispatches the row job with its payload when the cron runs', function (): void {
    $cron = app(DynamicSchedule::class)->create('eu', '* * * * *', DynamicCronProbeJob::class, ['region' => 'eu']);

    Bus::dispatch(new RunDynamicCron($cron->id));

    expect(DynamicCronProbeJob::$regions)->toBe(['eu']);
});

it('does nothing when the cron row was deleted', function (): void {
    Bus::fake([DynamicCronProbeJob::class]);

    Bus::dispatch(new RunDynamicCron(999));

    Bus::assertNothingDispatched();
});

it('rejects creating a cron for a job class outside the configured allowlist', function (): void {
    config()->set('zenith.dynamic_cron_allowed_classes', []);

    expect(fn (): mixed => app(DynamicSchedule::class)->create('often', '* * * * *', DynamicCronProbeJob::class))
        ->toThrow(InvalidArgumentException::class);
});

it('updates a cron and re-validates the expression, timezone, and job class', function (): void {
    $cron = app(DynamicSchedule::class)->create('often', '* * * * *', DynamicCronProbeJob::class);

    $updated = app(DynamicSchedule::class)->update(
        $cron->id,
        'often-renamed',
        '*/5 * * * *',
        DynamicCronProbeJob::class,
        ['region' => 'us'],
        'America/Sao_Paulo',
    );

    expect($updated->name)->toBe('often-renamed')
        ->and($updated->expression)->toBe('*/5 * * * *')
        ->and($updated->payload)->toBe(['region' => 'us'])
        ->and($updated->timezone)->toBe('America/Sao_Paulo');
});

it('rejects an update to a job class outside the configured allowlist', function (): void {
    $cron = app(DynamicSchedule::class)->create('often', '* * * * *', DynamicCronProbeJob::class);
    config()->set('zenith.dynamic_cron_allowed_classes', []);

    expect(fn (): mixed => app(DynamicSchedule::class)->update($cron->id, 'often', '* * * * *', DynamicCronProbeJob::class))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to dispatch a job class that is no longer allowlisted', function (): void {
    $cron = app(DynamicSchedule::class)->create('often', '* * * * *', DynamicCronProbeJob::class);
    config()->set('zenith.dynamic_cron_allowed_classes', []);

    Bus::dispatch(new RunDynamicCron($cron->id));

    expect(DynamicCronProbeJob::$regions)->toBe([]);
});

it('runs the dynamic cron tick from the scheduler', function (): void {
    Bus::fake([RunDynamicCron::class]);
    app(DynamicSchedule::class)->create('often', '* * * * *', DynamicCronProbeJob::class);

    scheduledZenithEvent('zenith:dynamic-crons')->run(app());

    Bus::assertDispatchedTimes(RunDynamicCron::class, 1);
});

it('ticks every minute and never overlaps a running dynamic cron tick', function (): void {
    expect(scheduledZenithEvent('zenith:dynamic-crons')->withoutOverlapping)->toBeTrue()
        ->and(scheduledZenithEvent('zenith:dynamic-crons')->getExpression())->toBe('* * * * *')
        ->and(scheduledZenithEvent('zenith:chunk-flush')->getExpression())->toBe('* * * * *');
});
