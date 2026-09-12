<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Actions;

use DevactionLabs\Zenith\Jobs\ForgetsPendingJob;
use DevactionLabs\Zenith\Jobs\PendingJobCancellationResult;
use DevactionLabs\Zenith\Jobs\PendingJobCancellationScope;
use DevactionLabs\Zenith\Support\RedisQueueName;
use DevactionLabs\Zenith\Support\RedisScript;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Laravel\Horizon\Contracts\JobRepository;
use RuntimeException;
use Throwable;

final readonly class CancelPendingJob
{
    public function __construct(
        private JobRepository $jobs,
        private QueueManager $queues,
        private ForgetsPendingJob $metadata,
        private ReleaseCancelledJobLocks $locks,
    ) {}

    public function handle(
        string $id,
        PendingJobCancellationScope $scope = PendingJobCancellationScope::Pending,
    ): PendingJobCancellationResult {
        $job = $this->jobs->getJobs([$id])->first();

        if (! is_object($job) || ($job->status ?? null) !== 'pending') {
            return PendingJobCancellationResult::NotCancellable;
        }

        $payload = $this->payload($job->payload ?? null);

        if ($this->belongsToBatch($payload)) {
            return PendingJobCancellationResult::Batched;
        }

        $connection = $job->connection ?? null;
        $queueName = $job->queue ?? null;
        $rawPayload = $job->payload ?? null;

        if (! is_string($connection) || $connection === '' ||
            ! is_string($queueName) || $queueName === '' ||
            ! is_string($rawPayload) || $rawPayload === '') {
            return PendingJobCancellationResult::NotCancellable;
        }

        $queue = $this->queues->connection($connection);

        if (! $queue instanceof RedisQueue) {
            throw new RuntimeException("Cancelling jobs is not supported for {$connection}.");
        }

        if (! $this->removeFromQueue($queue, $queueName, $rawPayload, $scope)) {
            return PendingJobCancellationResult::NotCancellable;
        }

        $this->cleanUp($id, $payload);

        return PendingJobCancellationResult::Cancelled;
    }

    private function removeFromQueue(
        RedisQueue $queue,
        string $queueName,
        string $payload,
        PendingJobCancellationScope $scope,
    ): bool {
        $redis = $queue->getConnection();
        $ready = $queue->getQueue(RedisQueueName::normalize($redis, $queueName));
        $removed = RedisScript::evaluate(
            $redis,
            <<<'LUA'
                local ready = 0
                local delayed = 0

                if ARGV[2] == '1' then
                    ready = redis.call('lrem', KEYS[1], 1, ARGV[1])

                    if ready > 0 then
                        redis.call('lpop', KEYS[3])
                    end
                end

                if ARGV[3] == '1' then
                    delayed = redis.call('zrem', KEYS[2], ARGV[1])
                end

                return delayed + ready
            LUA,
            3,
            $ready,
            $ready.':delayed',
            $ready.':notify',
            $payload,
            $scope->includesReadyJobs() ? '1' : '0',
            $scope->includesDelayedJobs() ? '1' : '0',
        );

        return is_numeric($removed) && (int) $removed > 0;
    }

    /** @param array<string, mixed> $payload */
    private function cleanUp(string $id, array $payload): void
    {
        try {
            $this->locks->handle($payload);
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $tags = array_values(array_filter(
                is_array($payload['tags'] ?? null) ? $payload['tags'] : [],
                is_string(...),
            ));

            $this->metadata->forgetPending($id, $tags);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return array<string, mixed> */
    private function payload(mixed $payload): array
    {
        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded)
            ? array_filter($decoded, is_string(...), ARRAY_FILTER_USE_KEY)
            : [];
    }

    /** @param array<string, mixed> $payload */
    private function belongsToBatch(array $payload): bool
    {
        $data = $payload['data'] ?? null;
        $batchId = is_array($data) ? ($data['batchId'] ?? null) : null;

        return is_string($batchId) && $batchId !== '';
    }
}
