<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

use DevactionLabs\Zenith\Telemetry\Data\JobAttemptData;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Records and reads per-job attempt history in a bounded Redis list.
 *
 * Every attempt is appended as a JSON object; the list is trimmed to the
 * configured per-job limit (oldest attempts dropped first) and carries a
 * TTL, so a job that is never revisited does not retain its history
 * forever. Only normalized fields are stored: exception class, a truncated
 * message, and a fingerprint, never a raw payload or full stack trace.
 */
final readonly class AttemptHistory
{
    private const int DEFAULT_PER_JOB_LIMIT = 25;

    private const int DEFAULT_TTL_SECONDS = 604_800;

    public function __construct(private RedisFactory $redis) {}

    public function record(string $jobId, JobAttemptData $attempt): void
    {
        $connection = $this->redis->connection('horizon');
        $key = TelemetryKeys::attempts($jobId);

        $connection->rpush($key, json_encode($this->toStorage($attempt), JSON_THROW_ON_ERROR));
        $connection->ltrim($key, -$this->perJobLimit(), -1);
        $connection->expire($key, $this->ttlSeconds());
    }

    /** @return list<JobAttemptData> */
    public function forJob(string $jobId): array
    {
        $connection = $this->redis->connection('horizon');
        $entries = $connection->lrange(TelemetryKeys::attempts($jobId), 0, -1);

        if (! is_array($entries)) {
            return [];
        }

        $attempts = [];

        foreach ($entries as $entry) {
            $attempt = $this->fromStorage($entry);

            if ($attempt !== null) {
                $attempts[] = $attempt;
            }
        }

        return $attempts;
    }

    /** @return array<string, mixed> */
    private function toStorage(JobAttemptData $attempt): array
    {
        return [
            'attempt' => $attempt->attempt,
            'outcome' => $attempt->outcome,
            'exceptionClass' => $attempt->exceptionClass,
            'message' => $attempt->message,
            'fingerprint' => $attempt->fingerprint,
            'runtimeMilliseconds' => $attempt->runtimeMilliseconds,
            'node' => $attempt->node,
            'occurredAt' => $attempt->occurredAt,
        ];
    }

    private function fromStorage(mixed $entry): ?JobAttemptData
    {
        if (! is_string($entry)) {
            return null;
        }

        $decoded = json_decode($entry, true);

        if (! is_array($decoded)) {
            return null;
        }

        $attempt = $decoded['attempt'] ?? null;
        $outcome = $decoded['outcome'] ?? null;
        $node = $decoded['node'] ?? null;
        $occurredAt = $decoded['occurredAt'] ?? null;

        if (! is_int($attempt) || ! is_string($outcome) || ! is_string($node) || ! is_int($occurredAt)) {
            return null;
        }

        $runtimeMilliseconds = $decoded['runtimeMilliseconds'] ?? null;

        return new JobAttemptData(
            attempt: $attempt,
            outcome: $outcome,
            exceptionClass: $this->nullableString($decoded['exceptionClass'] ?? null),
            message: $this->nullableString($decoded['message'] ?? null),
            fingerprint: $this->nullableString($decoded['fingerprint'] ?? null),
            runtimeMilliseconds: is_int($runtimeMilliseconds) ? $runtimeMilliseconds : null,
            node: $node,
            occurredAt: $occurredAt,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function perJobLimit(): int
    {
        $value = config('zenith.telemetry.attempts.per_job_limit');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_PER_JOB_LIMIT;
    }

    private function ttlSeconds(): int
    {
        $value = config('zenith.telemetry.attempts.ttl_seconds');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_TTL_SECONDS;
    }
}
