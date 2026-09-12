<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use ReflectionMethod;

final class RedisQueueName
{
    public static function normalize(Connection $connection, string $queue): string
    {
        if (! self::isCluster($connection) || self::hasHashTag($queue)) {
            return $queue;
        }

        return "{{$queue}}";
    }

    private static function isCluster(Connection $connection): bool
    {
        if ($connection instanceof PhpRedisClusterConnection
            || $connection instanceof PredisClusterConnection) {
            return true;
        }

        if (in_array('isCluster', get_class_methods($connection), true)) {
            return (bool) (new ReflectionMethod(
                $connection,
                'isCluster',
            ))->invoke($connection);
        }

        return false;
    }

    private static function hasHashTag(string $queue): bool
    {
        $open = strpos($queue, '{');

        if ($open === false) {
            return false;
        }

        $close = strpos($queue, '}', $open + 1);

        return $close !== false && $close - $open > 1;
    }
}
