<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Tests\Support;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * Minimal queue job stand-in for telemetry recorder tests.
 *
 * Only the methods the recorder actually calls are exercised; everything
 * else is inherited from the abstract Job base class, which already derives
 * behavior from the decoded payload.
 */
final class TelemetryFakeJob extends Job implements JobContract
{
    public function __construct(
        string $connectionName,
        string $queue,
        private readonly string $rawBody,
        private int $attempts = 1,
    ) {
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId(): string
    {
        $uuid = $this->payload()['uuid'] ?? null;

        return is_string($uuid) ? $uuid : 'unknown';
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function markFailedForTest(): void
    {
        $this->failed = true;
    }

    public function markReleasedForTest(): void
    {
        $this->released = true;
    }
}

/**
 * @param  array<string, mixed>  $overrides
 */
function telemetryFakeJob(array $overrides = [], int $attempts = 1): TelemetryFakeJob
{
    $payload = array_merge([
        'uuid' => 'job-uuid-1',
        'displayName' => 'App\\Jobs\\ImportFeed',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => null,
        'maxExceptions' => null,
        'backoff' => null,
        'timeout' => null,
        'retryUntil' => null,
        'createdAt' => 1_784_281_000,
        'data' => [
            'commandName' => 'App\\Jobs\\ImportFeed',
            'command' => 'serialized-secret-command',
        ],
    ], $overrides);

    return new TelemetryFakeJob(
        connectionName: 'redis',
        queue: 'default',
        rawBody: json_encode($payload, JSON_THROW_ON_ERROR),
        attempts: $attempts,
    );
}
