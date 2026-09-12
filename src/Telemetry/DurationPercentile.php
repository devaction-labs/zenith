<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * Estimates a percentile duration from a `DurationHistogram` bucket count
 * map instead of a raw, unbounded sample list.
 *
 * The estimate is the upper bound of the bucket in which the cumulative
 * distribution first reaches the target percentile, so it is accurate to
 * within that bucket's width (at most 2x, halving as the true value grows)
 * rather than exact.
 */
final class DurationPercentile
{
    /**
     * @param  array<int, int>  $histogram  bucket index => sample count
     */
    public static function estimate(array $histogram, float $percentile): ?int
    {
        $total = array_sum($histogram);

        if ($total <= 0) {
            return null;
        }

        ksort($histogram);
        $target = $percentile * $total;
        $cumulative = 0;

        foreach ($histogram as $bucketIndex => $count) {
            $cumulative += $count;

            if ($cumulative >= $target) {
                return DurationHistogram::bucketUpperBoundMilliseconds($bucketIndex);
            }
        }

        return null;
    }
}
