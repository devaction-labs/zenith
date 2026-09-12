<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * Log2-scaled duration histogram used to derive percentiles without storing
 * a raw sample per job.
 *
 * Each bucket doubles the previous one's upper bound (1ms, 2ms, 4ms, ...),
 * so `BUCKET_COUNT` buckets cover roughly 4.6 hours before durations clamp
 * into the final overflow bucket. Storing a bounded set of bucket counts
 * per dimension value keeps memory flat regardless of throughput, at the
 * cost of percentiles that are accurate to within one bucket's width.
 */
final class DurationHistogram
{
    public const int BUCKET_COUNT = 24;

    public static function bucketIndex(int $milliseconds): int
    {
        $milliseconds = max(0, $milliseconds);

        if ($milliseconds === 0) {
            return 0;
        }

        $index = (int) floor(log($milliseconds, 2));

        return max(0, min(self::BUCKET_COUNT - 1, $index));
    }

    public static function bucketUpperBoundMilliseconds(int $index): int
    {
        $index = max(0, min(self::BUCKET_COUNT - 1, $index));

        return (2 ** ($index + 1)) - 1;
    }
}
