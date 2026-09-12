<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Tracks jobs from `JobProcessing` until their terminal event in Redis.
 *
 * Each job gets its own hash with a TTL derived from its own timeout (or a
 * configured default, when the job declares none) plus a grace period, so a
 * worker that dies without dispatching a terminal event still has its entry
 * expire on its own. A companion sorted-set index lets `InFlightJobs` list
 * every running job without a Redis `SCAN`; that index carries a generous,
 * self-renewing TTL of its own and self-heals against entries whose job hash
 * already expired (see `InFlightJobs::hydrate()`).
 */
final readonly class InFlightJobTracker
{
    private const int INDEX_TTL_SECONDS = 604_800;

    public function __construct(private RedisFactory $redis) {}

    public function start(
        string $jobId,
        JobIdentity $job,
        WorkerIdentity $worker,
        ?int $timeoutSeconds,
    ): void {
        $connection = $this->redis->connection('horizon');
        $now = CarbonImmutable::now()->getTimestamp();
        $key = TelemetryKeys::inFlightJob($jobId);

        $connection->hset($key, 'queue', $job->queue);
        $connection->hset($key, 'class', $job->jobClass);
        $connection->hset($key, 'node', $worker->node);
        $connection->hset($key, 'supervisor', $worker->supervisor ?? '');
        $connection->hset($key, 'startedAt', (string) $now);
        $connection->hset($key, 'timeoutSeconds', $timeoutSeconds !== null ? (string) $timeoutSeconds : '');
        $connection->expire($key, $this->ttlSeconds($timeoutSeconds));

        $indexKey = TelemetryKeys::inFlightIndex();
        $connection->zadd($indexKey, $now, $jobId);
        $connection->expire($indexKey, self::INDEX_TTL_SECONDS);
    }

    public function finish(string $jobId): void
    {
        $connection = $this->redis->connection('horizon');

        $connection->del(TelemetryKeys::inFlightJob($jobId));
        $connection->zrem(TelemetryKeys::inFlightIndex(), $jobId);
    }

    private function ttlSeconds(?int $timeoutSeconds): int
    {
        $timeout = $timeoutSeconds ?? $this->configuredInt('default_timeout_seconds', 60);
        $grace = $this->configuredInt('grace_seconds', 60);

        return max(1, $timeout + $grace);
    }

    private function configuredInt(string $key, int $default): int
    {
        $value = config("zenith.telemetry.in_flight.{$key}");

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }
}
