<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Jobs\Actions;

use DevactionLabs\HorizonNewDawn\Jobs\ReleaseDelayedJobNowResult;
use DevactionLabs\HorizonNewDawn\Support\RedisQueueName;
use DevactionLabs\HorizonNewDawn\Support\RedisScript;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use JsonException;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\JobPayload;
use Laravel\Horizon\RedisQueue;
use RuntimeException;
use Throwable;

final readonly class ReleaseDelayedJobNow
{
    public function __construct(
        private JobRepository $jobs,
        private QueueManager $queues,
    ) {}

    public function handle(string $id): ReleaseDelayedJobNowResult
    {
        $job = $this->jobs->getJobs([$id])->first();

        if (! is_object($job) || ($job->status ?? null) !== 'pending') {
            return ReleaseDelayedJobNowResult::NotDelayed;
        }

        $connection = $job->connection ?? null;
        $queueName = $job->queue ?? null;
        $payload = $job->payload ?? null;

        if (! is_string($connection) || $connection === '' ||
            ! is_string($queueName) || $queueName === '' ||
            ! is_string($payload) || $payload === '') {
            return ReleaseDelayedJobNowResult::NotDelayed;
        }

        $queue = $this->queues->connection($connection);

        if (! $queue instanceof RedisQueue) {
            throw new RuntimeException("Releasing delayed jobs is not supported for {$connection}.");
        }

        $redis = $queue->getConnection();
        $ready = $queue->getQueue(RedisQueueName::normalize($redis, $queueName));
        $now = Date::now()->getTimestamp();
        $replacementPayload = $this->withMadeAvailableMetadata($payload, $now);

        if ($replacementPayload === null) {
            return ReleaseDelayedJobNowResult::NotDelayed;
        }

        $delayedScore = RedisScript::evaluate(
            $redis,
            <<<'LUA'
                local score = redis.call('zscore', KEYS[1], ARGV[1])

                if not score or redis.call('zrem', KEYS[1], ARGV[1]) == 0 then
                    return false
                end

                redis.call('rpush', KEYS[2], ARGV[2])
                redis.call('rpush', KEYS[3], 1)

                return score
            LUA,
            3,
            $ready.':delayed',
            $ready,
            $ready.':notify',
            $payload,
            $replacementPayload,
        );

        if (! is_numeric($delayedScore) || (float) $delayedScore <= 0) {
            return ReleaseDelayedJobNowResult::NotDelayed;
        }

        try {
            $this->jobs->migrated(
                $connection,
                $queueName,
                new Collection([new JobPayload($replacementPayload)]),
            );
        } catch (Throwable $exception) {
            try {
                RedisScript::evaluate(
                    $redis,
                    <<<'LUA'
                        if redis.call('lrem', KEYS[2], 1, ARGV[2]) == 0 then
                            return 0
                        end

                        redis.call('lpop', KEYS[3])
                        redis.call('zadd', KEYS[1], ARGV[3], ARGV[1])

                        return 1
                    LUA,
                    3,
                    $ready.':delayed',
                    $ready,
                    $ready.':notify',
                    $payload,
                    $replacementPayload,
                    (string) $delayedScore,
                );
            } catch (Throwable $rollbackException) {
                report($rollbackException);
            }

            throw $exception;
        }

        return ReleaseDelayedJobNowResult::Released;
    }

    private function withMadeAvailableMetadata(string $payload, int $timestamp): ?string
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                return null;
            }

            $metadata = is_array($decoded['horizonNewDawn'] ?? null)
                ? $decoded['horizonNewDawn']
                : [];
            $metadata['madeAvailableAt'] = $timestamp;
            $decoded['horizonNewDawn'] = $metadata;

            return json_encode($decoded, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
