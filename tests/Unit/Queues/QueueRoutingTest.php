<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Queues\QueueRouting;
use DevactionLabs\Zenith\Support\FrameworkCapabilities;

it('lists class routes that target a queue', function (): void {
    app('queue.routes')->set(QueueRouting::class, 'reports', 'redis');

    $routing = (new QueueRouting)->forQueue('reports', ['redis']);

    expect($routing->available)->toBeTrue()
        ->and($routing->classRoutes)->not->toBeEmpty()
        ->and($routing->classRoutes[0]->class)->toBe(QueueRouting::class)
        ->and($routing->forwardedQueue)->toBeNull();
});

it('resolves the route registered for a job class', function (): void {
    app('queue.routes')->set(QueueRouting::class, 'reports', 'redis');

    $route = (new QueueRouting)->forClass(QueueRouting::class);

    expect($route?->class)->toBe(QueueRouting::class)
        ->and($route?->queue)->toBe('reports')
        ->and($route?->connection)->toBe('redis');
});

it('returns null when a job class has no registered route', function (): void {
    expect((new QueueRouting)->forClass(QueueRouting::class))->toBeNull();
});

it('reports queues forwarded to another destination', function (): void {
    app('queue.routes')->forward('reports', 'reports.fifo', 'redis');

    $routing = (new QueueRouting)->forQueue('reports', ['redis']);

    expect($routing->available)->toBeTrue()
        ->and($routing->forwardedQueue)->toBe('reports.fifo')
        ->and($routing->forwardedConnection)->toBe('redis');
})->skip(
    fn (): bool => ! FrameworkCapabilities::queueForwardingSupported(),
    'Queue forwarding is unavailable on this Laravel version.',
);
