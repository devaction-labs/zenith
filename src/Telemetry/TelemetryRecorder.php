<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * Writes bounded, time-bucketed counters and duration histograms to Redis.
 *
 * Every recorded attempt issues the same small, fixed number of Redis
 * commands regardless of retained history: one hash increment per
 * dimension per metric per resolution, plus one index maintenance pass per
 * resolution. See `docs/architecture.md#telemetry` for the full storage
 * shape and the reasoning behind it.
 */
final readonly class TelemetryRecorder
{
    public function __construct(private RedisFactory $redis) {}

    public function record(
        TelemetryOutcome $outcome,
        JobIdentity $job,
        WorkerIdentity $worker,
        ?int $runtimeMilliseconds,
        ?int $waitMilliseconds,
    ): void {
        $connection = $this->redis->connection('horizon');
        $now = CarbonImmutable::now()->getTimestamp();

        /** @var list<array{0: TelemetryDimension, 1: string}> $dimensions */
        $dimensions = [
            [TelemetryDimension::Queue, $job->queue],
            [TelemetryDimension::JobClass, $job->jobClass],
            [TelemetryDimension::Node, $worker->node],
            [TelemetryDimension::Connection, $job->connection],
        ];

        foreach (TelemetryResolution::cases() as $resolution) {
            $this->writeBucket(
                $connection,
                $resolution,
                $now,
                $outcome,
                $dimensions,
                $runtimeMilliseconds,
                $waitMilliseconds,
            );
        }
    }

    /** @param  list<array{0: TelemetryDimension, 1: string}>  $dimensions */
    private function writeBucket(
        Connection $connection,
        TelemetryResolution $resolution,
        int $now,
        TelemetryOutcome $outcome,
        array $dimensions,
        ?int $runtimeMilliseconds,
        ?int $waitMilliseconds,
    ): void {
        $ttl = $resolution->retentionSeconds();
        $bucketStart = $resolution->bucketStart($now);
        $bucketKey = TelemetryKeys::bucket($resolution, $bucketStart);
        $indexKey = TelemetryKeys::index($resolution);

        $connection->zremrangebyscore($indexKey, '-inf', (string) ($now - $ttl));
        $connection->zadd($indexKey, $bucketStart, (string) $bucketStart);
        $connection->expire($indexKey, $ttl);

        foreach ($dimensions as [$dimension, $value]) {
            $connection->hincrby($bucketKey, TelemetryKeys::countField($dimension, $value, $outcome), 1);

            if ($runtimeMilliseconds !== null) {
                $connection->hincrby(
                    $bucketKey,
                    TelemetryKeys::histogramField(
                        TelemetryMetric::Runtime,
                        $dimension,
                        $value,
                        DurationHistogram::bucketIndex($runtimeMilliseconds),
                    ),
                    1,
                );
            }

            if ($waitMilliseconds !== null) {
                $connection->hincrby(
                    $bucketKey,
                    TelemetryKeys::histogramField(
                        TelemetryMetric::Wait,
                        $dimension,
                        $value,
                        DurationHistogram::bucketIndex($waitMilliseconds),
                    ),
                    1,
                );
            }
        }

        $connection->expire($bucketKey, $ttl);
    }
}
