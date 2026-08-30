<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Support;

final class RedisClusterDetector
{
    public static function enabled(): bool
    {
        $clusters = config('database.redis.clusters');

        return is_array($clusters) && $clusters !== [];
    }
}
