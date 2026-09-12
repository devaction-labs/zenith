<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * Gates telemetry event registration behind `zenith.telemetry.enabled`.
 *
 * When disabled, `register()` returns before touching the event dispatcher
 * at all, so the recorder adds zero listeners and zero overhead to queue
 * processing.
 */
final class TelemetryRegistration
{
    public static function enabled(): bool
    {
        return config('zenith.telemetry.enabled') === true;
    }

    public static function register(Dispatcher $events): void
    {
        if (! self::enabled()) {
            return;
        }

        $events->subscribe(TelemetryEventSubscriber::class);
    }
}
