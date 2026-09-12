<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\TelemetryEventSubscriber;
use DevactionLabs\Zenith\Telemetry\TelemetryRegistration;
use Illuminate\Contracts\Events\Dispatcher;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

it('subscribes the telemetry event listener when the recorder is enabled', function (): void {
    config()->set('zenith.telemetry.enabled', true);

    $events = mockDashboardContract(Dispatcher::class);
    dashboardExpects($events, 'subscribe', [TelemetryEventSubscriber::class]);

    TelemetryRegistration::register($events);
});

it('never touches the event dispatcher when the recorder is disabled', function (): void {
    config()->set('zenith.telemetry.enabled', false);

    $events = mockDashboardContract(Dispatcher::class);
    dashboardNeverReceives($events, 'subscribe');

    TelemetryRegistration::register($events);
});

it('treats a missing configuration value as disabled', function (): void {
    config()->set('zenith.telemetry.enabled', null);

    expect(TelemetryRegistration::enabled())->toBeFalse();
});
