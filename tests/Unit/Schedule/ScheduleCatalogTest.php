<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Schedule\DynamicSchedule;
use DevactionLabs\Zenith\Schedule\ScheduleCatalog;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('zenith_dynamic_crons');
});

it('hides Zenith scheduler ticks from the catalog', function (): void {
    $descriptions = array_map(
        fn (Event $event): ?string => $event->description,
        app(Schedule::class)->events(),
    );

    expect($descriptions)->toContain('zenith:dynamic-crons', 'zenith:chunk-flush')
        ->and(app(ScheduleCatalog::class)->events())->toBe([]);
});

it('assigns a stable id and next run to each scheduled event', function (): void {
    $schedule = app(Schedule::class);
    $schedule->command('inspire')->hourly()->timezone('UTC')->description('Inspire');

    $first = (new ScheduleCatalog($schedule, app(DynamicSchedule::class)))->events();
    $second = (new ScheduleCatalog($schedule, app(DynamicSchedule::class)))->events();

    expect($first)->toHaveCount(1)
        ->and($first[0]->id)->toBe($second[0]->id)
        ->and($first[0]->expression)->toBe('0 * * * *')
        ->and($first[0]->nextRunAt)->toBeFloat()
        ->and($first[0]->runtimeEditable)->toBeFalse();
});

it('finds an event by id for an on-demand run', function (): void {
    $schedule = app(Schedule::class);
    $schedule->command('inspire')->hourly()->description('Inspire');

    $catalog = new ScheduleCatalog($schedule, app(DynamicSchedule::class));
    $id = $catalog->events()[0]->id;

    expect($catalog->event($id))->not->toBeNull()
        ->and($catalog->event('missing'))->toBeNull();
});
