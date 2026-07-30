<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Support;

final class PollInterval
{
    private const int DEFAULT_MILLISECONDS = 5000;

    public static function milliseconds(): int
    {
        return max(
            0,
            (int) config(
                'horizon-new-dawn.poll_interval',
                self::DEFAULT_MILLISECONDS,
            ),
        );
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
