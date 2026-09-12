<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Telemetry\Data\PercentileChartData;
use DevactionLabs\Zenith\Telemetry\Data\PercentilePointData;
use DevactionLabs\Zenith\Telemetry\Data\ThroughputChartData;
use DevactionLabs\Zenith\Telemetry\Data\ThroughputSeriesData;
use DevactionLabs\Zenith\Telemetry\Data\ThroughputSeriesPointData;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Reads the recorder's bucketed counters and histograms into chart-ready
 * throughput and percentile data.
 *
 * Every read is scoped to the bucket resolution the requested window maps
 * to (see `TelemetryWindow::resolution()`) and to the bucket starts still
 * present in that resolution's index, so a window can never surface data
 * older than its tier's own retention. Falls back to `available: false`
 * whenever the recorder is disabled or the read itself fails, so callers
 * can fall back to Horizon's own snapshots (see `docs/architecture.md`).
 */
final readonly class TelemetryMetricsReader
{
    public const int MAX_SERIES = 5;

    private const TelemetryDimension STATE_DIMENSION = TelemetryDimension::Queue;

    public function __construct(private RedisFactory $redis) {}

    public function throughput(TelemetryWindow $window, TelemetryGroupBy $groupBy): ThroughputChartData
    {
        if (! TelemetryRegistration::enabled()) {
            return new ThroughputChartData(
                available: false,
                series: [],
                message: 'Enable the telemetry recorder (zenith.telemetry.enabled) to see live throughput.',
            );
        }

        try {
            $connection = $this->redis->connection('horizon');
            $buckets = $this->bucketsInWindow($connection, $window);
            $dimension = $groupBy->dimension() ?? self::STATE_DIMENSION;
            $totals = [];

            /** @var array<string, array<int, int>> $seriesPoints */
            $seriesPoints = [];

            foreach ($buckets as $bucketStart) {
                $fields = $connection->hgetall(TelemetryKeys::bucket($window->resolution(), $bucketStart));

                if (! is_array($fields)) {
                    continue;
                }

                foreach ($fields as $field => $rawCount) {
                    $parsed = TelemetryKeys::parseField((string) $field);

                    if ($parsed === null || $parsed['kind'] !== 'count' || $parsed['dimension'] !== $dimension) {
                        continue;
                    }

                    $key = $groupBy === TelemetryGroupBy::State
                        ? $parsed['outcome']->value
                        : $parsed['value'];
                    $count = (int) $rawCount;

                    $seriesPoints[$key][$bucketStart] = ($seriesPoints[$key][$bucketStart] ?? 0) + $count;
                    $totals[$key] = ($totals[$key] ?? 0) + $count;
                }
            }

            $keys = $groupBy === TelemetryGroupBy::State
                ? $this->orderStateKeys($totals)
                : $this->orderDimensionKeys($totals);

            $series = [];

            foreach ($keys as $key) {
                $points = [];

                foreach ($buckets as $bucketStart) {
                    $points[] = new ThroughputSeriesPointData(
                        timestamp: $bucketStart,
                        count: $seriesPoints[$key][$bucketStart] ?? 0,
                    );
                }

                $series[] = new ThroughputSeriesData(
                    label: $groupBy === TelemetryGroupBy::State
                        ? (TelemetryOutcome::from($key)->label())
                        : $key,
                    points: $points,
                );
            }

            return new ThroughputChartData(available: true, series: $series, message: null);
        } catch (Throwable $exception) {
            report($exception);

            return new ThroughputChartData(
                available: false,
                series: [],
                message: 'Live throughput is currently unavailable.',
            );
        }
    }

    public function percentiles(
        TelemetryWindow $window,
        TelemetryMetric $metric,
        TelemetryDimension $dimension,
        string $value,
    ): PercentileChartData {
        if (! TelemetryRegistration::enabled()) {
            return new PercentileChartData(
                available: false,
                points: [],
                message: 'Enable the telemetry recorder (zenith.telemetry.enabled) to see live percentiles.',
            );
        }

        try {
            $connection = $this->redis->connection('horizon');
            $buckets = $this->bucketsInWindow($connection, $window);
            $points = [];

            foreach ($buckets as $bucketStart) {
                $fields = $connection->hgetall(TelemetryKeys::bucket($window->resolution(), $bucketStart));
                $histogram = [];

                if (is_array($fields)) {
                    foreach ($fields as $field => $rawCount) {
                        $parsed = TelemetryKeys::parseField((string) $field);

                        if (
                            $parsed === null
                            || $parsed['kind'] !== 'hist'
                            || $parsed['metric'] !== $metric
                            || $parsed['dimension'] !== $dimension
                            || $parsed['value'] !== $value
                        ) {
                            continue;
                        }

                        $bucketIndex = $parsed['bucketIndex'];
                        $histogram[$bucketIndex] = ($histogram[$bucketIndex] ?? 0) + (int) $rawCount;
                    }
                }

                $points[] = new PercentilePointData(
                    timestamp: $bucketStart,
                    p50: DurationPercentile::estimate($histogram, 0.50),
                    p95: DurationPercentile::estimate($histogram, 0.95),
                    p99: DurationPercentile::estimate($histogram, 0.99),
                );
            }

            return new PercentileChartData(available: true, points: $points, message: null);
        } catch (Throwable $exception) {
            report($exception);

            return new PercentileChartData(
                available: false,
                points: [],
                message: 'Live percentiles are currently unavailable.',
            );
        }
    }

    /** @return list<int> */
    private function bucketsInWindow(Connection $connection, TelemetryWindow $window): array
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $windowStart = $now - $window->durationSeconds();
        $members = $connection->zrange(TelemetryKeys::index($window->resolution()), 0, -1);

        if (! is_array($members)) {
            return [];
        }

        $buckets = [];

        foreach ($members as $member) {
            if (! is_numeric($member)) {
                continue;
            }

            $bucketStart = (int) $member;

            if ($bucketStart >= $windowStart && $bucketStart <= $now) {
                $buckets[] = $bucketStart;
            }
        }

        sort($buckets);

        return $buckets;
    }

    /**
     * @param  array<string, int>  $totals
     * @return list<string>
     */
    private function orderStateKeys(array $totals): array
    {
        $keys = [];

        foreach (TelemetryOutcome::cases() as $outcome) {
            if (($totals[$outcome->value] ?? 0) > 0) {
                $keys[] = $outcome->value;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, int>  $totals
     * @return list<string>
     */
    private function orderDimensionKeys(array $totals): array
    {
        uasort($totals, static fn (int $left, int $right): int => $right <=> $left);

        return array_slice(array_keys($totals), 0, self::MAX_SERIES);
    }
}
