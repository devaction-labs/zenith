<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Jobs\Actions\CancelPendingJob;
use DevactionLabs\Zenith\Jobs\Actions\ReleaseCancelledJobLocks;
use DevactionLabs\Zenith\Jobs\Actions\ReleaseDelayedJobNow;
use DevactionLabs\Zenith\Jobs\ForgetsPendingJob;
use DevactionLabs\Zenith\Jobs\PendingJobCancellationResult;
use DevactionLabs\Zenith\Jobs\ReleaseDelayedJobNowResult;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\JobPayload;
use Laravel\Horizon\RedisQueue;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

describe('ReleaseDelayedJobNow', function (): void {
    it('makes the exact delayed payload immediately eligible and migrates it through Horizon', function (): void {
        Date::setTestNow('2026-07-23 12:34:56');

        $job = horizonJob(0, 'delayed-1');
        $job->status = 'pending';
        $job->connection = 'redis';
        $job->queue = 'imports';

        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($jobs, 'getJobs', [[$job->id]], new Collection([$job]));
        $migratedConnection = null;
        $migratedQueue = null;
        $migratedPayloads = null;
        dashboardExpects(
            $jobs,
            'migrated',
            times: 'once',
            returnUsing: function (
                string $connection,
                string $queue,
                Collection $payloads,
            ) use (&$migratedConnection, &$migratedQueue, &$migratedPayloads): void {
                $migratedConnection = $connection;
                $migratedQueue = $queue;
                $migratedPayloads = $payloads;
            },
        );

        $redis = new ReleaseDelayedJobRedisConnection(1);
        $queue = new ReleaseDelayedJobRedisQueue($redis);
        $result = (new ReleaseDelayedJobNow(
            $jobs,
            new ReleaseDelayedJobQueueManager(app(), $queue),
        ))->handle($job->id);

        expect($result)->toBe(ReleaseDelayedJobNowResult::Released)
            ->and($redis->commands)->toHaveCount(1)
            ->and($queue->migrations)->toBe([]);

        [$method, $arguments] = $redis->commands[0];
        $replacementPayloadJson = $arguments[6] ?? null;
        $replacementPayload = is_string($replacementPayloadJson)
            ? json_decode($replacementPayloadJson, true)
            : null;

        expect($method)->toBe('eval')
            ->and($arguments[0] ?? '')->toContain("redis.call('zrem', KEYS[1], ARGV[1])")
            ->and($arguments[0] ?? '')->toContain("redis.call('rpush', KEYS[2], ARGV[2])")
            ->and($arguments[0] ?? '')->toContain("redis.call('rpush', KEYS[3], 1)")
            ->and($arguments[0] ?? '')->not->toContain("redis.call('zadd'")
            ->and($arguments[1] ?? null)->toBe(3)
            ->and($arguments[2] ?? null)->toBe('queues:imports:delayed')
            ->and($arguments[3] ?? null)->toBe('queues:imports')
            ->and($arguments[4] ?? null)->toBe('queues:imports:notify')
            ->and($arguments[5] ?? null)->toBe($job->payload)
            ->and(data_get($replacementPayload, 'displayName'))->toBe('App\\Jobs\\ImportFeed')
            ->and(data_get($replacementPayload, 'zenith.madeAvailableAt'))
            ->toBe(Date::now()->getTimestamp());

        $migratedPayload = $migratedPayloads?->first();

        expect($migratedConnection)->toBe('redis')
            ->and($migratedQueue)->toBe('imports')
            ->and($migratedPayloads)->toBeInstanceOf(Collection::class)
            ->and($migratedPayloads)->toHaveCount(1)
            ->and($migratedPayload)->toBeInstanceOf(JobPayload::class)
            ->and($migratedPayload instanceof JobPayload ? $migratedPayload->value : null)
            ->toBe($arguments[6]);
    });

    it('does not migrate a job that left the delayed set before the action ran', function (): void {
        $job = horizonJob(0, 'delayed-1');
        $job->status = 'pending';

        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($jobs, 'getJobs', [[$job->id]], new Collection([$job]));

        $redis = new ReleaseDelayedJobRedisConnection(0);
        $queue = new ReleaseDelayedJobRedisQueue($redis);
        $result = (new ReleaseDelayedJobNow(
            $jobs,
            new ReleaseDelayedJobQueueManager(app(), $queue),
        ))->handle($job->id);

        expect($result)->toBe(ReleaseDelayedJobNowResult::NotDelayed)
            ->and($queue->migrations)->toBe([]);
    });

    it('restores the exact delayed payload when Horizon metadata migration fails', function (): void {
        $job = horizonJob(0, 'delayed-1');
        $job->status = 'pending';
        $job->queue = 'imports';

        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($jobs, 'getJobs', [[$job->id]], new Collection([$job]));
        dashboardExpects(
            $jobs,
            'migrated',
            times: 'once',
            returnUsing: static fn (): never => throw new RuntimeException('metadata unavailable'),
        );

        $redis = new ReleaseDelayedJobRedisConnection(['1784281200', 1]);
        $queue = new ReleaseDelayedJobRedisQueue($redis);
        $release = new ReleaseDelayedJobNow(
            $jobs,
            new ReleaseDelayedJobQueueManager(app(), $queue),
        );

        expect(fn (): ReleaseDelayedJobNowResult => $release->handle($job->id))
            ->toThrow(RuntimeException::class, 'metadata unavailable')
            ->and($redis->commands)->toHaveCount(2);

        [$method, $arguments] = $redis->commands[1];

        expect($method)->toBe('eval')
            ->and($arguments[0] ?? '')->toContain("redis.call('lrem', KEYS[2], 1, ARGV[2])")
            ->and($arguments[0] ?? '')->toContain("redis.call('zadd', KEYS[1], ARGV[3], ARGV[1])")
            ->and($arguments[1] ?? null)->toBe(3)
            ->and($arguments[2] ?? null)->toBe('queues:imports:delayed')
            ->and($arguments[3] ?? null)->toBe('queues:imports')
            ->and($arguments[4] ?? null)->toBe('queues:imports:notify')
            ->and($arguments[5] ?? null)->toBe($job->payload)
            ->and($arguments[7] ?? null)->toBe('1784281200');
    });

    it('keeps the released queue payload aligned so the same job can still be cancelled', function (): void {
        $job = horizonJob(0, 'delayed-1');
        $job->status = 'pending';
        $job->queue = 'imports';
        $job->payload = json_encode([
            'uuid' => $job->id,
            'displayName' => $job->name,
            'tags' => ['tenant:42'],
            'data' => ['batchId' => null],
        ], JSON_THROW_ON_ERROR);

        $jobs = mockDashboardContract(JobRepository::class);
        dashboardExpects(
            $jobs,
            'getJobs',
            [[$job->id]],
            times: 'twice',
            value: new Collection([$job]),
        );
        dashboardExpects(
            $jobs,
            'migrated',
            times: 'once',
            returnUsing: static function (
                string $connection,
                string $queue,
                Collection $payloads,
            ) use ($job): void {
                $payload = $payloads->first();

                expect($connection)->toBe('redis')
                    ->and($queue)->toBe('imports')
                    ->and($payload)->toBeInstanceOf(JobPayload::class);

                if (! $payload instanceof JobPayload) {
                    throw new LogicException('Expected Horizon to migrate a job payload.');
                }

                $job->payload = $payload->value;
                $job->delay = 0;
            },
        );

        $redis = new ReleaseThenCancelRedisConnection;
        $queue = new ReleaseDelayedJobRedisQueue($redis);
        $manager = new ReleaseDelayedJobQueueManager(app(), $queue);
        $metadata = new ReleaseThenCancelMetadata;

        $released = (new ReleaseDelayedJobNow($jobs, $manager))->handle($job->id);
        $cancelled = (new CancelPendingJob(
            $jobs,
            $manager,
            $metadata,
            new ReleaseCancelledJobLocks(
                app(CacheFactory::class),
                app(Encrypter::class),
            ),
        ))->handle($job->id);

        expect($released)->toBe(ReleaseDelayedJobNowResult::Released)
            ->and($cancelled)->toBe(PendingJobCancellationResult::Cancelled)
            ->and($redis->commands)->toHaveCount(2)
            ->and($redis->commands[1][1][5] ?? null)->toBe($redis->commands[0][1][6] ?? null)
            ->and($metadata->forgotten)->toBe([[$job->id, ['tenant:42']]]);
    });
});

final class ReleaseDelayedJobQueueManager extends QueueManager
{
    public function __construct($app, private readonly Queue $queue)
    {
        parent::__construct($app);
    }

    public function connection($name = null): Queue
    {
        return $this->queue;
    }
}

final class ReleaseDelayedJobRedisQueue extends RedisQueue
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $migrations = [];

    public function __construct(private readonly Connection $redisConnection) {}

    public function getConnection(): Connection
    {
        return $this->redisConnection;
    }

    public function getQueue($queue): string
    {
        $name = $queue instanceof BackedEnum
            ? (string) $queue->value
            : (is_string($queue) && $queue !== '' ? $queue : 'default');

        return 'queues:'.$name;
    }

    /** @return array<int, string> */
    public function migrateExpiredJobs($from, $to): array
    {
        $this->migrations[] = [$from, $to];

        return [];
    }
}

final class ReleaseDelayedJobRedisConnection extends Connection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $commands = [];

    /** @var array<int, mixed> */
    private array $results;

    /** @param int|array<int, mixed> $results */
    public function __construct(int|array $results)
    {
        $this->results = is_array($results) ? $results : [$results];
    }

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, $callback, $method = 'subscribe'): void {}

    /** @param array<int, mixed> $parameters */
    public function command($method, array $parameters = []): mixed
    {
        $this->commands[] = [$method, $parameters];

        return array_shift($this->results) ?? 0;
    }
}

final class ReleaseThenCancelRedisConnection extends Connection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $commands = [];

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, $callback, $method = 'subscribe'): void {}

    /** @param array<int, mixed> $parameters */
    public function command($method, array $parameters = []): int
    {
        $this->commands[] = [$method, $parameters];

        $script = $parameters[0] ?? null;

        return is_string($script) && str_contains($script, "redis.call('zscore'")
            ? 1_784_281_200
            : 1;
    }
}

final class ReleaseThenCancelMetadata implements ForgetsPendingJob
{
    /** @var array<int, array{0: string, 1: array<int, string>}> */
    public array $forgotten = [];

    public function forgetPending(string $id, array $tags): bool
    {
        $this->forgotten[] = [$id, $tags];

        return true;
    }
}
