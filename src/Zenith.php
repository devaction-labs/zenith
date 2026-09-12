<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith;

use Closure;

/**
 * Extension points a host application registers from its own service provider.
 */
final class Zenith
{
    /** @var (Closure(string, string): ?string)|null */
    private static ?Closure $explainFailureUsing = null;

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
