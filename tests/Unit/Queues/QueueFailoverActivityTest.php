<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Queues\QueueFailoverActivity;

it('records nothing recent when no failover has happened', function (): void {
    $activity = new QueueFailoverActivity(app('cache'));

    expect($activity->recent())->toBe([]);
});

it('records a failover with its connection name', function (): void {
    $activity = new QueueFailoverActivity(app('cache'));

    $activity->record('redis');

    $recent = $activity->recent();

    expect($recent)->toHaveCount(1)
        ->and($recent[0]['connection'])->toBe('redis');
});

it('records multiple failovers and keeps a null connection name', function (): void {
    $activity = new QueueFailoverActivity(app('cache'));

    $activity->record('redis');
    $activity->record(null);

    expect($activity->recent())->toHaveCount(2);
});

it('drops failovers once they age out of the configured window', function (): void {
    config()->set('zenith.queue_failover.window_minutes', 1);
    $activity = new QueueFailoverActivity(app('cache'));

    $activity->record('redis');

    expect($activity->recent())->toHaveCount(1);

    $this->travel(61)->seconds();

    expect($activity->recent())->toBe([]);
});

it('reports the configured window in minutes', function (): void {
    config()->set('zenith.queue_failover.window_minutes', 45);
    $activity = new QueueFailoverActivity(app('cache'));

    expect($activity->windowMinutes())->toBe(45);
});

it('stores failover activity in the configured cache store', function (): void {
    config()->set('cache.stores.failovers', ['driver' => 'array']);
    config()->set('zenith.queue_failover.store', 'failovers');

    $activity = new QueueFailoverActivity(app('cache'));
    $activity->record('redis');

    config()->set('zenith.queue_failover.store', null);

    expect((new QueueFailoverActivity(app('cache')))->recent())->toBe([]);

    config()->set('zenith.queue_failover.store', 'failovers');

    expect((new QueueFailoverActivity(app('cache')))->recent())->toHaveCount(1);
});
