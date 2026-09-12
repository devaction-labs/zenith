<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Queues\QueueStarvationAlert;
use DevactionLabs\Zenith\Queues\QueueStarvationStatus;

it('monitors a queue whose oldest ready job is within the starvation threshold', function (): void {
    config()->set('zenith.starvation.threshold_seconds', 300);

    $alert = (new QueueStarvationAlert)->evaluate(299);

    expect($alert->status)->toBe(QueueStarvationStatus::Monitoring)
        ->and($alert->thresholdSeconds)->toBe(300)
        ->and($alert->oldestReadyAgeSeconds)->toBe(299);
});

it('flags a queue as starved once its oldest ready job exceeds the threshold', function (): void {
    config()->set('zenith.starvation.threshold_seconds', 300);

    $alert = (new QueueStarvationAlert)->evaluate(301);

    expect($alert->status)->toBe(QueueStarvationStatus::Starved)
        ->and($alert->oldestReadyAgeSeconds)->toBe(301);
});

it('is not starved exactly at the threshold', function (): void {
    config()->set('zenith.starvation.threshold_seconds', 300);

    expect((new QueueStarvationAlert)->evaluate(300)->status)->toBe(QueueStarvationStatus::Monitoring);
});

it('monitors a queue with no ready jobs to age', function (): void {
    $alert = (new QueueStarvationAlert)->evaluate(null);

    expect($alert->status)->toBe(QueueStarvationStatus::Monitoring)
        ->and($alert->oldestReadyAgeSeconds)->toBeNull();
});

it('falls back to a five-minute default threshold when unconfigured', function (): void {
    config()->set('zenith.starvation.threshold_seconds', null);

    expect((new QueueStarvationAlert)->evaluate(301)->thresholdSeconds)->toBe(300);
});

it('uses a configured starvation threshold', function (): void {
    config()->set('zenith.starvation.threshold_seconds', 30);

    expect((new QueueStarvationAlert)->evaluate(31)->status)->toBe(QueueStarvationStatus::Starved)
        ->and((new QueueStarvationAlert)->evaluate(29)->status)->toBe(QueueStarvationStatus::Monitoring);
});
