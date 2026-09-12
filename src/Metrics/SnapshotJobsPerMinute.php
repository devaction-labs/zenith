<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Project jobs/min from processed-since-snapshot over fractional elapsed minutes.
 *
 * Horizon's jobsProcessedPerMinute() floors elapsed time at 1 minute, which under-reports
 * during the first minute after a snapshot. Dashboard and queue detail both use this
 * projection so the rate cannot drift between screens.
 */
final readonly class SnapshotJobsPerMinute
{
    public function __construct(
        private RedisFactory $redis,
    ) {}

    public function project(int $processedSinceSnapshot): int|float
    {
        try {
            $lastSnapshotAt = $this->redis->connection('horizon')->get('last_snapshot_at');

            return self::calculate($processedSinceSnapshot, $lastSnapshotAt);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    public static function calculate(int $processedSinceSnapshot, mixed $lastSnapshotAt): int|float
    {
        if (! is_numeric($lastSnapshotAt)) {
            return 0;
        }

        $elapsedSeconds = CarbonImmutable::now()->getTimestamp() - (int) $lastSnapshotAt;

        if ($elapsedSeconds <= 0) {
            return 0;
        }

        return round($processedSinceSnapshot / ($elapsedSeconds / 60), 2);
    }
}
