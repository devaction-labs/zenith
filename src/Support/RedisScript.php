<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use ReflectionMethod;

final class RedisScript
{
    public static function evaluate(
        Connection $connection,
        string $script,
        int $numberOfKeys,
        mixed ...$arguments,
    ): mixed {
        if ($connection instanceof PhpRedisConnection) {
            return $connection->command('eval', [
                $script,
                $arguments,
                $numberOfKeys,
            ]);
        }

        if (method_exists($connection, 'eval')) {
            return (new ReflectionMethod($connection, 'eval'))->invokeArgs(
                $connection,
                [$script, $numberOfKeys, ...$arguments],
            );
        }

        return $connection->command('eval', [
            $script,
            $numberOfKeys,
            ...$arguments,
        ]);
    }
}
