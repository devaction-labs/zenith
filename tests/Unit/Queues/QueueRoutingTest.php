<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Queues\QueueRouting;
use Illuminate\Queue\QueueRoutes;

it('lists class routes that target a queue and reports forwards', function (): void {
    if (! app()->bound('queue.routes')) {
        $this->markTestSkipped('Queue routes are unavailable on this Laravel version.');
    }

    /** @var QueueRoutes $routes */
    $routes = app('queue.routes');
    $routes->set(QueueRouting::class, 'reports', 'redis');
    $routes->forward('reports', 'reports.fifo', 'redis');

    $routing = (new QueueRouting)->forQueue('reports', ['redis']);

    expect($routing->available)->toBeTrue()
        ->and($routing->classRoutes)->not->toBeEmpty()
        ->and($routing->classRoutes[0]->class)->toBe(QueueRouting::class);
});
