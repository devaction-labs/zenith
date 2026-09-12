<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Signals;

use Illuminate\Contracts\Cache\Repository;

final class Signal
{
    private const string PREFIX = 'zenith:signal:';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function send(string $name, array $payload = []): void
    {
        self::store()->put(self::PREFIX.$name, $payload, 3600);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pull(string $name): ?array
    {
        $key = self::PREFIX.$name;
        $payload = self::store()->get($key);
        self::store()->forget($key);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function await(string $name, int $seconds = 30): array
    {
        $payload = self::pull($name);

        if ($payload !== null) {
            return $payload;
        }

        throw new SignalTimeoutException("Signal [{$name}] did not arrive within {$seconds} seconds.");
    }

    private static function store(): Repository
    {
        return app(Repository::class);
    }
}
