<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Support;

final class PollInterval
{
    private const int DEFAULT_MILLISECONDS = 5000;

    public static function milliseconds(): int
    {
        $milliseconds = config('zenith.poll_interval', self::DEFAULT_MILLISECONDS);

        return is_numeric($milliseconds) ? max(0, (int) $milliseconds) : 0;
    }

    public static function cacheSeconds(): int
    {
        return intdiv(self::milliseconds(), 1000);
    }

    public static function retainedCacheSeconds(): int
    {
        return max(
            1,
            intdiv(self::milliseconds() + 999, 1000),
        );
    }
}
