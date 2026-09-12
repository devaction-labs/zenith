<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith;

use Closure;

final class Zenith
{
    private static ?Closure $redactPayloadCallback = null;

    /**
     * Register a callback that fully replaces the configured key-pattern
     * redaction with host-defined logic for every job, failed-job, and
     * workflow payload the package exposes to Inertia.
     *
     * @param  callable(array<string, mixed>): mixed  $callback
     */
    public static function redactPayloadUsing(callable $callback): void
    {
        self::$redactPayloadCallback = Closure::fromCallable($callback);
    }

    public static function redactPayloadCallback(): ?Closure
    {
        return self::$redactPayloadCallback;
    }

    public static function resetRedactPayloadUsing(): void
    {
        self::$redactPayloadCallback = null;
    }
}
