<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Queues\QueueFailoverActivity;
use DevactionLabs\Zenith\Queues\RecordQueueFailover;
use Illuminate\Queue\Events\QueueFailedOver;
use Illuminate\Support\Facades\Event;
use RuntimeException;

it('records the failed-over connection when the event fires', function (): void {
    $activity = new QueueFailoverActivity(app('cache'));
    $listener = new RecordQueueFailover($activity);

    $listener(new QueueFailedOver('redis', 'App\\Jobs\\ImportFeed', new RuntimeException('unreachable')));

    expect($activity->recent())->toHaveCount(1)
        ->and($activity->recent()[0]['connection'])->toBe('redis');
});

it('is registered against the real QueueFailedOver event', function (): void {
    Event::dispatch(
        new QueueFailedOver('redis', 'App\\Jobs\\ImportFeed', new RuntimeException('unreachable')),
    );

    expect(app(QueueFailoverActivity::class)->recent())->toHaveCount(1);
});
