<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Support\HorizonRuntime;
use Illuminate\Queue\Events\QueueFailedOver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Horizon;
use RuntimeException;

use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\get;

beforeEach(function (): void {
    Horizon::auth(static fn (): bool => true);
    config()->set('zenith.poll_interval', 0);

    $masters = mockDashboardContract(MasterSupervisorRepository::class);
    dashboardReturns($masters, 'all', [(object) ['name' => 'horizon-web-01', 'status' => 'running']]);
    app()->instance(HorizonRuntime::class, new HorizonRuntime($masters));
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
});

it('shows the bypass warning on the dashboard after a simulated queue failover', function (): void {
    Event::dispatch(new QueueFailedOver(
        'redis',
        'App\\Jobs\\ImportFeed',
        new RuntimeException('Redis connection refused'),
    ));

    get('/horizon')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Dashboard')
            ->where('queueBypassWarning.hasRecentFailovers', true)
            ->where('queueBypassWarning.recentFailoverCount', 1)
            ->where('queueBypassWarning.recentFailoverConnections', ['redis']));
});

it('keeps the dashboard bypass warning quiet without a recent failover', function (): void {
    get('/horizon')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('queueBypassWarning.hasRecentFailovers', false)
            ->where('queueBypassWarning.recentFailoverCount', 0));
});

it('flags connections configured with a bypass-prone driver on the queues page', function (): void {
    config()->set('queue.connections.legacy-failover', [
        'driver' => 'failover',
        'connections' => ['redis', 'database'],
    ]);

    get('/horizon/queues')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Queues/Index')
            ->where(
                'queueBypassWarning.bypassProneConnections',
                fn (Collection $connections): bool => $connections->contains('legacy-failover'),
            ));
});
