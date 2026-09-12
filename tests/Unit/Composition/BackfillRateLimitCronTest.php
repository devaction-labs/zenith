<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Backfills\Backfill;
use DevactionLabs\Zenith\Limits\QueueBudget;
use DevactionLabs\Zenith\Schedule\DynamicSchedule;
use DevactionLabs\Zenith\Schedule\RunDynamicCron;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    app(Repository::class)->clear();
    Schema::dropIfExists('zenith_dynamic_crons');
    Schema::create('zenith_dynamic_crons', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->string('expression');
        $table->string('job_class');
        $table->json('payload')->nullable();
        $table->boolean('paused')->default(false);
        $table->string('timezone')->nullable();
        $table->timestamp('last_ran_at')->nullable();
        $table->timestamps();
    });
});

final class NumberBackfillPage
{
    /** @var list<int> */
    public static array $seen = [];

    /**
     * @return array{cursor: int, done: bool}
     */
    public function __invoke(?int $cursor): array
    {
        $cursor ??= 0;
        self::$seen[] = $cursor;

        if ($cursor >= 2) {
            return ['cursor' => $cursor, 'done' => true];
        }

        return ['cursor' => $cursor + 1, 'done' => false];
    }
}

it('walks a backfill until the cursor is exhausted', function (): void {
    $seen = [];

    Backfill::make(function (?int $cursor) use (&$seen): array {
        $cursor ??= 0;
        $seen[] = $cursor;

        if ($cursor >= 2) {
            return ['cursor' => $cursor, 'done' => true];
        }

        return ['cursor' => $cursor + 1, 'done' => false];
    })->dispatch();

    expect($seen)->toBe([0, 1, 2]);
});

it('releases when a global rate limit is exhausted', function (): void {
    $budget = app(QueueBudget::class);
    $budget->consume('api', allowed: 1, period: 60);

    expect($budget->allows('api', allowed: 1, period: 60))->toBeFalse()
        ->and($budget->allows('other', allowed: 1, period: 60))->toBeTrue();
});

it('stores a runtime cron that can be paused', function (): void {
    $crons = app(DynamicSchedule::class);
    $event = $crons->create('nightly', '0 3 * * *', FetchWorkflowStep::class);

    expect($crons->events())->toHaveCount(1)
        ->and($event->paused)->toBeFalse();

    $crons->pause($event->id);

    expect($crons->events()[0]->paused)->toBeTrue();
});

it('queues continuation pages for class-based backfills', function (): void {
    NumberBackfillPage::$seen = [];

    Backfill::make(NumberBackfillPage::class)->dispatch();

    expect(NumberBackfillPage::$seen)->toBe([0, 1, 2]);
});

it('dispatches due dynamic crons once per minute', function (): void {
    Bus::fake([RunDynamicCron::class]);

    $crons = app(DynamicSchedule::class);
    $crons->create('often', '* * * * *', FetchWorkflowStep::class);

    expect($crons->tick())->toBe(1)
        ->and($crons->tick())->toBe(0);

    Bus::assertDispatched(RunDynamicCron::class);
});

it('skips paused dynamic crons on tick', function (): void {
    Bus::fake([RunDynamicCron::class]);

    $crons = app(DynamicSchedule::class);
    $event = $crons->create('paused', '* * * * *', FetchWorkflowStep::class);
    $crons->pause($event->id);

    expect($crons->tick())->toBe(0);
    Bus::assertNothingDispatched();
});

it('registers scheduler ticks for dynamic crons and chunk flush', function (): void {
    $names = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->description);

    expect($names)->toContain('zenith:dynamic-crons')
        ->and($names)->toContain('zenith:chunk-flush');
});

it('holds a global concurrency slot until it is released', function (): void {
    $budget = app(QueueBudget::class);

    expect($budget->acquireSlot('imports', 1))->toBeTrue()
        ->and($budget->acquireSlot('imports', 1))->toBeFalse();

    $budget->releaseSlot('imports');

    expect($budget->acquireSlot('imports', 1))->toBeTrue();
});
