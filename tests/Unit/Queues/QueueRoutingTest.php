<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Queues\QueueRouting;
use Illuminate\Queue\QueueRoutes;

it('lists class routes that target a queue and reports forwards', function (): void {
    if (! app()->bound('queue.routes')) {
        skip('Queue routes are unavailable on this Laravel version.');
    }

    $routes = app('queue.routes');

    if (! $routes instanceof QueueRoutes || ! method_exists($routes, 'set')) {
        skip('Queue routes cannot be configured on this Laravel version.');
    }

    $routes->set('App\\Jobs\\ProcessReport', 'reports', 'redis');

    if (method_exists($routes, 'forward')) {
        $routes->forward('reports', 'reports.fifo', 'redis');
    }

    $routing = (new QueueRouting)->forQueue('reports', ['redis']);

    expect($routing->available)->toBeTrue()
        ->and($routing->classRoutes)->not->toBeEmpty()
        ->and($routing->classRoutes[0]->class)->toBe('App\\Jobs\\ProcessReport');
});
