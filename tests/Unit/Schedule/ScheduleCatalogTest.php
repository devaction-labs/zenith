<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Schedule\ScheduleCatalog;
use Illuminate\Console\Scheduling\Schedule;

it('assigns a stable id and next run to each scheduled event', function (): void {
    $schedule = app(Schedule::class);
    $schedule->command('inspire')->hourly()->timezone('UTC')->description('Inspire');

    $first = (new ScheduleCatalog($schedule))->events();
    $second = (new ScheduleCatalog($schedule))->events();

    expect($first)->toHaveCount(1)
        ->and($first[0]->id)->toBe($second[0]->id)
        ->and($first[0]->expression)->toBe('0 * * * *')
        ->and($first[0]->nextRunAt)->toBeFloat()
        ->and($first[0]->runtimeEditable)->toBeFalse();
});

it('finds an event by id for an on-demand run', function (): void {
    $schedule = app(Schedule::class);
    $schedule->command('inspire')->hourly()->description('Inspire');

    $catalog = new ScheduleCatalog($schedule);
    $id = $catalog->events()[0]->id;

    expect($catalog->event($id))->not->toBeNull()
        ->and($catalog->event('missing'))->toBeNull();
});
