<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Queues\QueueBypassWarning;
use DevactionLabs\Zenith\Queues\QueueFailoverActivity;

it('reports no warning when nothing failed over and no connection bypasses Horizon', function (): void {
    config()->set('queue.connections', [
        'redis' => ['driver' => 'redis'],
        'sync' => ['driver' => 'sync'],
    ]);
    $warning = new QueueBypassWarning(new QueueFailoverActivity(app('cache')));

    $summary = $warning->summary();

    expect($summary->hasRecentFailovers)->toBeFalse()
        ->and($summary->recentFailoverCount)->toBe(0)
        ->and($summary->recentFailoverConnections)->toBe([])
        ->and($summary->bypassProneConnections)->toBe([]);
});

it('reports recent failovers by connection', function (): void {
    config()->set('queue.connections', ['redis' => ['driver' => 'redis']]);
    $failovers = new QueueFailoverActivity(app('cache'));
    $failovers->record('redis');
    $failovers->record('redis');
    $warning = new QueueBypassWarning($failovers);

    $summary = $warning->summary();

    expect($summary->hasRecentFailovers)->toBeTrue()
        ->and($summary->recentFailoverCount)->toBe(2)
        ->and($summary->recentFailoverConnections)->toBe(['redis']);
});

it('flags connections configured with a bypass-prone driver', function (): void {
    config()->set('queue.connections', [
        'redis' => ['driver' => 'redis'],
        'primary-failover' => ['driver' => 'failover', 'connections' => ['redis', 'database']],
        'offline' => ['driver' => 'deferred'],
        'background-tasks' => ['driver' => 'background'],
        'sync' => ['driver' => 'sync'],
    ]);
    $warning = new QueueBypassWarning(new QueueFailoverActivity(app('cache')));

    expect($warning->summary()->bypassProneConnections)
        ->toBe(['primary-failover', 'offline', 'background-tasks']);
});

it('excludes connections listed in the ignored_connections allowlist', function (): void {
    config()->set('queue.connections', [
        'redis' => ['driver' => 'redis'],
        'primary-failover' => ['driver' => 'failover', 'connections' => ['redis', 'database']],
        'offline' => ['driver' => 'deferred'],
        'background-tasks' => ['driver' => 'background'],
        'sync' => ['driver' => 'sync'],
    ]);
    config()->set('zenith.queue_failover.ignored_connections', ['primary-failover', 'offline']);
    $warning = new QueueBypassWarning(new QueueFailoverActivity(app('cache')));

    expect($warning->summary()->bypassProneConnections)->toBe(['background-tasks']);
});

it('excludes recent failover activity for ignored connections from the summary', function (): void {
    config()->set('queue.connections', ['primary-failover' => ['driver' => 'failover']]);
    config()->set('zenith.queue_failover.ignored_connections', ['primary-failover']);
    $failovers = new QueueFailoverActivity(app('cache'));
    $failovers->record('primary-failover');
    $failovers->record('primary-failover');
    $warning = new QueueBypassWarning($failovers);

    $summary = $warning->summary();

    expect($summary->hasRecentFailovers)->toBeFalse()
        ->and($summary->recentFailoverCount)->toBe(0)
        ->and($summary->recentFailoverConnections)->toBe([])
        ->and($summary->bypassProneConnections)->toBe([]);
});

it('still reports failover activity for connections not in the allowlist', function (): void {
    config()->set('queue.connections', [
        'primary-failover' => ['driver' => 'failover'],
        'secondary-failover' => ['driver' => 'failover'],
    ]);
    config()->set('zenith.queue_failover.ignored_connections', ['primary-failover']);
    $failovers = new QueueFailoverActivity(app('cache'));
    $failovers->record('primary-failover');
    $failovers->record('secondary-failover');
    $warning = new QueueBypassWarning($failovers);

    $summary = $warning->summary();

    expect($summary->hasRecentFailovers)->toBeTrue()
        ->and($summary->recentFailoverCount)->toBe(1)
        ->and($summary->recentFailoverConnections)->toBe(['secondary-failover'])
        ->and($summary->bypassProneConnections)->toBe(['secondary-failover']);
});
