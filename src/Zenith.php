<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith;

use Closure;

/**
 * Extension points a host application registers from its own service provider.
 */
final class Zenith
{
    private static ?Closure $redactPayloadCallback = null;

    /** @var (Closure(string, string): ?string)|null */
    private static ?Closure $explainFailureUsing = null;

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

    /**
     * @param  (callable(string, string): ?string)|null  $callback
     */
    public static function explainFailureUsing(?callable $callback): void
    {
        self::$explainFailureUsing = $callback === null ? null : Closure::fromCallable($callback);
    }

    public static function explainsFailures(): bool
    {
        return self::$explainFailureUsing !== null;
    }

    public static function explainFailure(string $jobClass, string $exceptionMessage): ?string
    {
        if (self::$explainFailureUsing === null) {
            return null;
        }

        return (self::$explainFailureUsing)($jobClass, $exceptionMessage);
    }
}
