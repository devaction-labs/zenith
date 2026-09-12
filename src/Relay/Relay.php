<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Relay;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use RuntimeException;

final class Relay
{
    private const string PREFIX = 'zenith:relay:';

    public static function async(object $job): string
    {
        $id = (string) Str::uuid();
        app(Dispatcher::class)->dispatch(new RunRelayedJob($id, $job));

        return $id;
    }

    public static function await(string $id, int $seconds = 30): mixed
    {
        $result = app(Repository::class)->get(self::PREFIX.$id);

        if (is_array($result) && array_key_exists('value', $result)) {
            return $result['value'];
        }

        if (is_array($result) && array_key_exists('error', $result)) {
            throw new RuntimeException((string) $result['error']);
        }

        throw new RuntimeException("Relay [{$id}] did not finish within {$seconds} seconds.");
    }

    public static function record(string $id, mixed $value): void
    {
        app(Repository::class)->put(self::PREFIX.$id, ['value' => $value], 3600);
    }

    public static function fail(string $id, string $message): void
    {
        app(Repository::class)->put(self::PREFIX.$id, ['error' => $message], 3600);
    }
}
