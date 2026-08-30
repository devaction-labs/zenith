<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobRetryLock;
use DevactionLabs\HorizonNewDawn\Jobs\Data\JobIndexFiltersData;
use DevactionLabs\HorizonNewDawn\Jobs\PendingJobStateIndex;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobCursor;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobFilterCatalog;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobIndex;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobQuery;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobType;
use DevactionLabs\HorizonNewDawn\Support\RedisScript;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Horizon;

use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturns;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

final class PendingSnapshotObservingConnection extends Connection
{
    /** @var array<int, array{keys: array<int, string>, ttls: array<string, int>}> */
    public array $createdSnapshots = [];

    /** @var array<int, string> */
    public array $physicalSnapshotKeys = [];

    private bool $hasReadSnapshot = false;

    public function __construct(
        private readonly Connection $inner,
        private readonly ?Closure $afterFirstRead = null,
        private readonly ?Connection $raw = null,
        private readonly bool $deleteGuardAfterFirstRead = false,
    ) {}

    /** @param array<int, string>|string $channels */
    public function createSubscription(
        $channels,
        Closure $callback,
        $method = 'subscribe',
    ): void {}

    public function client(): self
    {
        return $this;
    }

    public function eval(
        string $script,
        int $keyCount,
        string ...$parameters,
    ): mixed {
        $parameters = array_values($parameters);
        $keys = array_slice($parameters, 0, $keyCount);
        $result = RedisScript::evaluate(
            $this->inner,
            $script,
            $keyCount,
            ...$parameters,
        );

        if (str_contains($script, "local timestamp = redis.call('time')")) {
            // Per-state snapshots use 5 keys; consolidated pendingQueueEntries uses 8.
            if (count($keys) === 5) {
                $snapshotKeys = [$keys[0], $keys[3], $keys[4]];
                $namespace = ':horizon-new-dawn:pending-snapshot:';
            } elseif (count($keys) === 8) {
                $snapshotKeys = [
                    $keys[0],
                    $keys[4],
                    $keys[5],
                    $keys[6],
                    $keys[7],
                ];
                $namespace = ':horizon-new-dawn:pending-queue-snapshot:';
            } else {
                throw new LogicException(
                    'Expected five or eight pending snapshot keys.',
                );
            }

            $ttls = [];

            foreach ($snapshotKeys as $key) {
                $ttls[$key] = (int) $this->inner->ttl($key);
            }

            $this->createdSnapshots[] = [
                'keys' => $snapshotKeys,
                'ttls' => $ttls,
            ];

            if ($this->raw !== null) {
                $physicalKeys = $this->raw->keys('*');
                $physicalKeys = is_array($physicalKeys)
                    ? array_values(array_filter($physicalKeys, is_string(...)))
                    : [];
                $this->physicalSnapshotKeys = [
                    ...$this->physicalSnapshotKeys,
                    ...array_values(array_filter(
                        $physicalKeys,
                        static fn (string $key): bool => str_contains(
                            $key,
                            $namespace,
                        ),
                    )),
                ];
            }
        }

        if (
            str_contains($script, 'guard ~= ARGV[4]')
            && ! $this->hasReadSnapshot
        ) {
            $this->hasReadSnapshot = true;

            if ($this->afterFirstRead !== null) {
                ($this->afterFirstRead)($this->inner);
            }

            if ($this->deleteGuardAfterFirstRead) {
                $this->inner->del($keys[0]);
            }
        }

        return $result;
    }

    public function del(string ...$keys): int
    {
        return max(0, (int) $this->inner->del(...$keys));
    }
}

it('queries retained jobs through the configured real Redis client', function (): void {
    $environment = retainedJobRedisCompatibilityEnvironment();
    configureRetainedJobCompatibilityRedis($environment);

    $redis = app(RedisFactory::class);
    $horizonRedis = $redis->connection('horizon');
    $rawRedis = $redis->connection('retained_job_compatibility_raw');
    $rawRedis->flushdb();

    try {
        $expectedConnection = $environment['REDIS_CLIENT'] === 'phpredis'
            ? PhpRedisConnection::class
            : PredisConnection::class;

        expect($horizonRedis)->toBeInstanceOf($expectedConnection);

        $matchingIds = seedRetainedJobCompatibilityJobs($horizonRedis);
        $jobs = app(JobRepository::class);
        $hydratedSeed = $jobs->getJobs([$matchingIds[0]])->first();

        expect($horizonRedis->zcard(
            RetainedJobType::Completed->sourceKey(),
        ))->toBe(120)
            ->and($hydratedSeed?->name)->toBe(
                'App\\Jobs\\GenerateCompatibilityReport',
            );

        $nonMatchingId = 'retained-compatibility-008';
        $horizonRedis->hmset($nonMatchingId, [
            'name' => 'App\\Jobs\\MaintenanceTask',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\MaintenanceTask',
                'tags' => [],
            ], JSON_THROW_ON_ERROR),
        ]);
        $index = new RetainedJobIndex($redis, $jobs);
        $index->synchronize(RetainedJobType::Completed);

        expect($horizonRedis->zcard(
            $index->projectionKey(RetainedJobType::Completed),
        ))->toBe(120)
            ->and($horizonRedis->zcard($index->facetKey(
                RetainedJobType::Completed,
                'job',
                'App\\Jobs\\GenerateCompatibilityReport',
            )))->toBe(75);

        $query = new RetainedJobQuery($jobs, $index);
        $searchPage = $query->page(
            RetainedJobType::Completed,
            JobIndexFiltersData::none(),
            -1,
            search: 'CompatibilityReport',
        );
        $exactId = $matchingIds[60];
        $exactIdPage = $query->page(
            RetainedJobType::Completed,
            JobIndexFiltersData::none(),
            -1,
            search: $exactId,
        );

        expect($searchPage->total)->toBe(119)
            ->and($searchPage->jobs->pluck('id'))->not->toContain($nonMatchingId)
            ->and($exactIdPage->total)->toBe(1)
            ->and($exactIdPage->jobs->pluck('id')->all())->toBe([$exactId]);

        $filters = new JobIndexFiltersData(
            job: 'App\\Jobs\\GenerateCompatibilityReport',
            queue: 'retained-reports',
            connection: 'redis-analytics',
            state: null,
        );
        $matchingJobFacet = $index->facetKey(
            RetainedJobType::Completed,
            'job',
            'App\\Jobs\\GenerateCompatibilityReport',
        );
        $horizonRedis->del($matchingJobFacet);

        $firstPage = $query->page(
            RetainedJobType::Completed,
            $filters,
            -1,
        );
        $firstPageIds = $firstPage->jobs->pluck('id')->all();

        // A missing facet forces a generation publish; resolve the live key after page.
        $rebuiltJobFacet = $index->facetKey(
            RetainedJobType::Completed,
            'job',
            'App\Jobs\GenerateCompatibilityReport',
        );

        expect($firstPage->total)->toBe(75)
            ->and($firstPageIds)->toBe(array_slice($matchingIds, 0, 50))
            ->and($firstPage->next)->toBeString()
            ->and($firstPage->next)->toContain('.')
            ->and($horizonRedis->zcard($rebuiltJobFacet))->toBe(75);

        $cursor = $firstPage->next;

        if (! is_string($cursor)) {
            throw new LogicException('Expected a retained job cursor.');
        }

        $cursorBoundaryId = $firstPageIds[array_key_last($firstPageIds)];
        $cursorPosition = (new RetainedJobCursor)->decode(
            $cursor,
            RetainedJobType::Completed,
            $query->signature(RetainedJobType::Completed, $filters),
        );
        [$encodedCursor, $encodedSignature] = explode('.', $cursor, 2);
        $tamperedSignature = ($encodedSignature[0] === 'A' ? 'B' : 'A')
            .substr($encodedSignature, 1);
        $tamperedCursor = $encodedCursor.'.'.$tamperedSignature;

        expect($cursorPosition?->id)->toBe($cursorBoundaryId)
            ->and($cursorPosition?->offset)->toBe(50)
            ->and(fn () => $query->page(
                RetainedJobType::Completed,
                $filters,
                $tamperedCursor,
            ))->toThrow(RuntimeException::class, 'invalid');

        $horizonRedis->zrem(
            RetainedJobType::Completed->sourceKey(),
            $cursorBoundaryId,
        );
        $horizonRedis->del($cursorBoundaryId);
        $index->synchronize(RetainedJobType::Completed, force: true);

        $secondPage = $query->page(
            RetainedJobType::Completed,
            $filters,
            $cursor,
        );
        $secondPageIds = $secondPage->jobs->pluck('id')->all();

        expect($secondPage->total)->toBe(74)
            ->and($secondPage->current)->toBe($cursor)
            ->and($secondPageIds)->toBe(array_slice($matchingIds, 50))
            ->and(array_intersect($firstPageIds, $secondPageIds))->toBe([])
            ->and($secondPage->next)->toBeNull();

        $catalog = (new RetainedJobFilterCatalog($index))->for(
            RetainedJobType::Completed,
        );
        $physicalKeys = array_values(array_filter(
            $rawRedis->keys('*'),
            is_string(...),
        ));
        $temporaryKeys = array_values(array_filter(
            $physicalKeys,
            static fn (string $key): bool => str_contains(
                $key,
                ':temporary:',
            ),
        ));
        $synchronizationLocks = array_values(array_filter(
            $physicalKeys,
            static fn (string $key): bool => str_ends_with(
                $key,
                ':synchronize-lock',
            ),
        ));
        $outsideHorizonNamespace = array_values(array_filter(
            $physicalKeys,
            static fn (string $key): bool => ! str_starts_with(
                $key,
                $environment['HORIZON_PREFIX'],
            ),
        ));

        expect($index->metadataKey())->toStartWith(
            "\x1fhorizon-new-dawn:v2:",
        )->and($catalog->queues)->toContain(
            'maintenance',
            'retained-reports',
        )->and($catalog->connections)->toContain(
            'redis',
            'redis-analytics',
        )->and($physicalKeys)->toContain(
            $environment['HORIZON_PREFIX']
                .RetainedJobType::Completed->sourceKey(),
            $environment['HORIZON_PREFIX'].$index->metadataKey(),
        )->and($temporaryKeys)->toBe([])
            ->and($synchronizationLocks)->toBe([])
            ->and($outsideHorizonNamespace)->toBe([]);
    } finally {
        $rawRedis->flushdb();
    }
});

it('trims expired retained references before building the real Redis index', function (): void {
    $environment = retainedJobRedisCompatibilityEnvironment();
    configureRetainedJobCompatibilityRedis($environment);

    $redis = app(RedisFactory::class);
    $horizonRedis = $redis->connection('horizon');
    $rawRedis = $redis->connection('retained_job_compatibility_raw');
    $rawRedis->flushdb();

    try {
        $activeId = 'retained-compatibility-active';
        $expiredId = 'retained-compatibility-expired';
        $retainedAt = microtime(true);
        $horizonRedis->zadd(
            RetainedJobType::Completed->sourceKey(),
            -$retainedAt,
            $activeId,
        );
        $horizonRedis->zadd(
            RetainedJobType::Completed->sourceKey(),
            -($retainedAt - 7200),
            $expiredId,
        );
        $horizonRedis->hmset($activeId, [
            'id' => $activeId,
            'connection' => 'redis',
            'queue' => 'default',
            'name' => 'App\\Jobs\\ActiveCompatibilityReport',
            'status' => 'completed',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\ActiveCompatibilityReport',
                'tags' => [],
            ], JSON_THROW_ON_ERROR),
            'completed_at' => (string) $retainedAt,
        ]);

        $jobs = app(JobRepository::class);
        $index = new RetainedJobIndex($redis, $jobs);
        $index->synchronize(RetainedJobType::Completed);

        expect($horizonRedis->zcard(
            RetainedJobType::Completed->sourceKey(),
        ))->toBe(1)
            ->and($horizonRedis->zrange(
                RetainedJobType::Completed->sourceKey(),
                0,
                -1,
            ))->toBe([$activeId])
            ->and($horizonRedis->zcard(
                $index->projectionKey(RetainedJobType::Completed),
            ))->toBe(1);
    } finally {
        $rawRedis->flushdb();
    }
});

it('cleans a stale real Redis projection when the retained source becomes empty', function (): void {
    $environment = retainedJobRedisCompatibilityEnvironment();
    configureRetainedJobCompatibilityRedis($environment);

    $redis = app(RedisFactory::class);
    $horizonRedis = $redis->connection('horizon');
    $rawRedis = $redis->connection('retained_job_compatibility_raw');
    $rawRedis->flushdb();

    try {
        $id = 'retained-compatibility-removed';
        $retainedAt = microtime(true);
        $horizonRedis->zadd(
            RetainedJobType::Completed->sourceKey(),
            -$retainedAt,
            $id,
        );
        $horizonRedis->hmset($id, [
            'id' => $id,
            'connection' => 'redis',
            'queue' => 'default',
            'name' => 'App\\Jobs\\RemovedCompatibilityReport',
            'status' => 'completed',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RemovedCompatibilityReport',
                'tags' => [],
            ], JSON_THROW_ON_ERROR),
            'completed_at' => (string) $retainedAt,
        ]);

        $jobs = app(JobRepository::class);
        $index = new RetainedJobIndex($redis, $jobs);
        $index->synchronize(RetainedJobType::Completed);
        $horizonRedis->zrem(
            RetainedJobType::Completed->sourceKey(),
            $id,
        );
        $horizonRedis->del($id);

        $index->synchronize(RetainedJobType::Completed, force: true);

        $physicalKeys = array_values(array_filter(
            $rawRedis->keys('*'),
            is_string(...),
        ));

        expect($horizonRedis->zcard(
            RetainedJobType::Completed->sourceKey(),
        ))->toBe(0)
            ->and($horizonRedis->zcard(
                $index->projectionKey(RetainedJobType::Completed),
            ))->toBe(0)
            ->and($horizonRedis->hexists($index->metadataKey(), $id))
            ->toBeFalsy()
            ->and(array_filter(
                $physicalKeys,
                static fn (string $key): bool => str_contains(
                    $key,
                    ':temporary:',
                ),
            ))->toBe([])
            ->and(array_filter(
                $physicalKeys,
                static fn (string $key): bool => str_ends_with(
                    $key,
                    ':synchronize-lock',
                ),
            ))->toBe([]);
    } finally {
        $rawRedis->flushdb();
    }
});

it('re-synchronizes when the retained source changes during hydration', function (): void {
    $environment = retainedJobRedisCompatibilityEnvironment();
    configureRetainedJobCompatibilityRedis($environment);

    $redis = app(RedisFactory::class);
    $horizonRedis = $redis->connection('horizon');
    $rawRedis = $redis->connection('retained_job_compatibility_raw');
    $rawRedis->flushdb();

    try {
        seedRetainedJobCompatibilityJobs($horizonRedis);
        $storedJobs = app(JobRepository::class);
        $jobs = mockDashboardContract(JobRepository::class);
        dashboardReturnsUsing(
            $jobs,
            'trimRecentJobs',
            static function () use ($storedJobs): void {
                $storedJobs->trimRecentJobs();
            },
        );
        $concurrentId = 'retained-compatibility-concurrent';
        $inserted = false;
        dashboardReturnsUsing(
            $jobs,
            'getJobs',
            static function (array $ids) use (
                &$inserted,
                $concurrentId,
                $horizonRedis,
                $storedJobs,
            ): Collection {
                if (! $inserted) {
                    $horizonRedis->zadd(
                        RetainedJobType::Completed->sourceKey(),
                        -microtime(true),
                        $concurrentId,
                    );
                    $horizonRedis->hmset($concurrentId, [
                        'id' => $concurrentId,
                        'connection' => 'redis',
                        'queue' => 'default',
                        'name' => 'App\\Jobs\\ConcurrentCompatibilityReport',
                        'status' => 'completed',
                        'payload' => json_encode([
                            'displayName' => 'App\\Jobs\\ConcurrentCompatibilityReport',
                            'tags' => ['compatibility:concurrent'],
                        ], JSON_THROW_ON_ERROR),
                        'completed_at' => '121',
                    ]);
                    $inserted = true;
                }

                return $storedJobs->getJobs($ids);
            },
        );
        $index = new RetainedJobIndex($redis, $jobs);
        $query = new RetainedJobQuery($jobs, $index);

        $page = $query->page(
            RetainedJobType::Completed,
            new JobIndexFiltersData(
                job: null,
                queue: null,
                connection: 'redis',
                state: null,
            ),
            -1,
        );

        expect($inserted)->toBeTrue()
            ->and($page->total)->toBe(46)
            ->and($page->jobs->pluck('id'))->toContain($concurrentId)
            ->and($horizonRedis->zcard(
                $index->projectionKey(RetainedJobType::Completed),
            ))->toBe(121);
    } finally {
        $rawRedis->flushdb();
    }
});

it('scans immutable pending snapshots and cleans them through the configured real Redis client', function (): void {
    $environment = retainedJobRedisCompatibilityEnvironment();
    configureRetainedJobCompatibilityRedis($environment);

    $redis = app(RedisFactory::class);
    $horizonRedis = $redis->connection('horizon');
    $rawRedis = $redis->connection('retained_job_compatibility_raw');
    $rawRedis->flushdb();
    $target = [['connection' => 'redis', 'queue' => 'default']];
    $queueKey = 'queues:default';

    try {
        $readyPayloads = array_map(
            static fn (int $index): string => json_encode(
                ['uuid' => "ready-{$index}"],
                JSON_THROW_ON_ERROR,
            ),
            range(0, 600),
        );
        $horizonRedis->rpush($queueKey, ...$readyPayloads);
        $readyConnection = new PendingSnapshotObservingConnection(
            $horizonRedis,
            static function (Connection $connection) use ($queueKey): void {
                $connection->ltrim($queueKey, 100, -1);
            },
            $rawRedis,
        );
        $readyStates = pendingSnapshotCompatibilityStateIndex(
            $readyConnection,
        );
        $ready = $readyStates->matchingIds($target, 'ready');
        $readySnapshot = $readyConnection->createdSnapshots[0] ?? null;

        if (! is_array($readySnapshot)) {
            throw new LogicException('Expected a ready snapshot.');
        }

        expect($ready)->toHaveCount(601)
            ->and($ready)->toHaveKeys(['ready-0', 'ready-550'])
            ->and($readySnapshot['ttls'][$readySnapshot['keys'][0]])->toBeGreaterThan(0)
            ->and($readySnapshot['ttls'][$readySnapshot['keys'][1]])->toBeGreaterThan(0)
            ->and($readySnapshot['ttls'][$readySnapshot['keys'][2]])->toBe(-2);

        $remainingReadySnapshotKeys = array_values(array_filter(
            $readySnapshot['keys'],
            static fn (string $key): bool => (int) $horizonRedis->exists($key) !== 0,
        ));
        $outsideHorizonNamespace = array_values(array_filter(
            $readyConnection->physicalSnapshotKeys,
            static fn (string $key): bool => ! str_starts_with(
                $key,
                $environment['HORIZON_PREFIX'],
            ),
        ));

        expect($remainingReadySnapshotKeys)->toBe([])
            ->and($readyConnection->physicalSnapshotKeys)->not->toBeEmpty()
            ->and($outsideHorizonNamespace)->toBe([]);

        $reservedKey = "{$queueKey}:reserved";

        foreach (range(0, 600) as $index) {
            $horizonRedis->zadd(
                $reservedKey,
                $index,
                json_encode(
                    ['uuid' => "reserved-{$index}"],
                    JSON_THROW_ON_ERROR,
                ),
            );
        }

        $reservedConnection = new PendingSnapshotObservingConnection(
            $horizonRedis,
            static function (Connection $connection) use ($reservedKey): void {
                $connection->zremrangebyrank($reservedKey, 0, 99);
            },
            $rawRedis,
        );
        $reservedStates = pendingSnapshotCompatibilityStateIndex(
            $reservedConnection,
        );
        $reserved = $reservedStates->matchingIds($target, 'reserved');
        $reservedSnapshot = $reservedConnection->createdSnapshots[0] ?? null;

        if (! is_array($reservedSnapshot)) {
            throw new LogicException('Expected a reserved snapshot.');
        }

        expect($reserved)->toHaveCount(601)
            ->and($reserved)->toHaveKeys([
                'reserved-0',
                'reserved-550',
            ])->and($reservedSnapshot['ttls'][$reservedSnapshot['keys'][0]])->toBeGreaterThan(0)
            ->and($reservedSnapshot['ttls'][$reservedSnapshot['keys'][1]])->toBe(-2)
            ->and($reservedSnapshot['ttls'][$reservedSnapshot['keys'][2]])->toBeGreaterThan(0);

        $remainingReservedSnapshotKeys = array_values(array_filter(
            $reservedSnapshot['keys'],
            static fn (string $key): bool => (int) $horizonRedis->exists($key) !== 0,
        ));

        expect($remainingReservedSnapshotKeys)->toBe([]);

        $expiringConnection = new PendingSnapshotObservingConnection(
            $horizonRedis,
            raw: $rawRedis,
            deleteGuardAfterFirstRead: true,
        );
        $expiringStates = pendingSnapshotCompatibilityStateIndex(
            $expiringConnection,
        );

        expect(fn (): array => $expiringStates->matchingIds(
            $target,
            'ready',
        ))->toThrow(
            RuntimeException::class,
            'snapshot expired; refresh is required',
        );

        $expiringSnapshot = $expiringConnection->createdSnapshots[0] ?? null;

        if (! is_array($expiringSnapshot)) {
            throw new LogicException('Expected an expiring snapshot.');
        }

        $remainingExpiredSnapshotKeys = array_values(array_filter(
            $expiringSnapshot['keys'],
            static fn (string $key): bool => (int) $horizonRedis->exists($key) !== 0,
        ));

        expect($remainingExpiredSnapshotKeys)->toBe([]);
    } finally {
        $rawRedis->flushdb();
    }
});

it('queries only the relevant delayed score window through the configured real Redis client', function (): void {
    $environment = retainedJobRedisCompatibilityEnvironment();
    configureRetainedJobCompatibilityRedis($environment);

    $redis = app(RedisFactory::class);
    $horizonRedis = $redis->connection('horizon');
    $rawRedis = $redis->connection('retained_job_compatibility_raw');
    $rawRedis->flushdb();

    try {
        $queue = new RedisQueue($redis, 'default', 'default');
        $connection = $queue->getConnection();
        $queueKey = $queue->getQueue('default');
        $serverTime = $horizonRedis->time();
        $asOf = $serverTime[0];
        $futurePayload = json_encode(['uuid' => 'future'], JSON_THROW_ON_ERROR);
        $duePayload = json_encode(['uuid' => 'due'], JSON_THROW_ON_ERROR);
        $connection->zadd("{$queueKey}:delayed", $asOf + 60, $futurePayload);
        $connection->zadd("{$queueKey}:delayed", $asOf - 1, $duePayload);

        $queues = mockDashboardContract(QueueFactory::class);
        dashboardReturns($queues, 'connection', $queue);
        $states = new PendingJobStateIndex($queues);
        $target = [['connection' => 'redis', 'queue' => 'default']];

        expect($states->matchingIds($target, 'delayed'))->toBe([
            'future' => true,
        ])->and($states->matchingIds($target, 'released'))->toBe([
            'due' => true,
        ]);
    } finally {
        $rawRedis->flushdb();
    }
});

it('captures a coherent pendingQueueEntries inventory with state tags, scores, and cleanup', function (): void {
    $environment = retainedJobRedisCompatibilityEnvironment();
    configureRetainedJobCompatibilityRedis($environment);

    $redis = app(RedisFactory::class);
    $horizonRedis = $redis->connection('horizon');
    $rawRedis = $redis->connection('retained_job_compatibility_raw');
    $rawRedis->flushdb();

    try {
        $queue = new RedisQueue($redis, 'default', 'default');
        $connection = $queue->getConnection();
        $queueKey = $queue->getQueue('default');
        $serverTime = $horizonRedis->time();
        $asOf = (int) $serverTime[0];

        $readyPayload = json_encode([
            'uuid' => 'ready-job',
            'pushedAt' => $asOf,
        ], JSON_THROW_ON_ERROR);
        $releasedReadyPayload = json_encode([
            'uuid' => 'released-ready',
            'createdAt' => $asOf - 120,
            'delay' => 60,
            'pushedAt' => $asOf - 120,
        ], JSON_THROW_ON_ERROR);
        $connection->rpush($queueKey, $readyPayload, $releasedReadyPayload);

        $reservedPayload = json_encode([
            'uuid' => 'reserved-job',
            'pushedAt' => $asOf,
        ], JSON_THROW_ON_ERROR);
        $reservedScore = $asOf + 90;
        $connection->zadd("{$queueKey}:reserved", $reservedScore, $reservedPayload);

        $futurePayload = json_encode([
            'uuid' => 'delayed-future',
            'pushedAt' => $asOf,
        ], JSON_THROW_ON_ERROR);
        $futureScore = $asOf + 60;
        $connection->zadd("{$queueKey}:delayed", $futureScore, $futurePayload);

        $duePayload = json_encode([
            'uuid' => 'delayed-due',
            'pushedAt' => $asOf - 10,
        ], JSON_THROW_ON_ERROR);
        $dueScore = $asOf - 1;
        $connection->zadd("{$queueKey}:delayed", $dueScore, $duePayload);

        $queues = mockDashboardContract(QueueFactory::class);
        dashboardReturns($queues, 'connection', $queue);
        $states = new PendingJobStateIndex($queues);

        $entries = $states->pendingQueueEntries([
            'connection' => 'redis',
            'queue' => 'default',
        ]);
        $byId = [];

        foreach ($entries as $entry) {
            $byId[$entry['id']] = $entry;
        }

        expect($entries)->toHaveCount(5)
            ->and(array_column($entries, 'id'))->toBe([
                'ready-job',
                'released-ready',
                'reserved-job',
                'delayed-future',
                'delayed-due',
            ])
            ->and($byId['ready-job']['state'])->toBe('ready')
            ->and($byId['ready-job']['score'])->toBeNull()
            ->and($byId['ready-job']['connection'])->toBe('redis')
            ->and($byId['ready-job']['queue'])->toBe('default')
            ->and($byId['released-ready']['state'])->toBe('released')
            ->and($byId['released-ready']['score'])->toBeNull()
            ->and($byId['reserved-job']['state'])->toBe('reserved')
            ->and($byId['reserved-job']['score'])->toBe((float) $reservedScore)
            ->and($byId['delayed-future']['state'])->toBe('delayed')
            ->and($byId['delayed-future']['score'])->toBe((float) $futureScore)
            ->and($byId['delayed-due']['state'])->toBe('released')
            ->and($byId['delayed-due']['score'])->toBe((float) $dueScore)
            ->and($byId['delayed-due']['payload']['uuid'] ?? null)->toBe('delayed-due');

        $allKeys = $rawRedis->keys('*');
        $allKeys = is_array($allKeys) ? array_map(strval(...), $allKeys) : [];
        $leftoverQueueSnapshots = array_values(array_filter(
            $allKeys,
            static fn (string $key): bool => str_contains($key, ':horizon-new-dawn:pending-queue-snapshot:'),
        ));
        $leftoverStateSnapshots = array_values(array_filter(
            $allKeys,
            static fn (string $key): bool => str_contains($key, ':horizon-new-dawn:pending-snapshot:'),
        ));

        expect($leftoverQueueSnapshots)->toBe([])
            ->and($leftoverStateSnapshots)->toBe([]);

        $expiringConnection = new PendingSnapshotObservingConnection(
            $horizonRedis,
            raw: $rawRedis,
            deleteGuardAfterFirstRead: true,
        );
        $expiringStates = pendingSnapshotCompatibilityStateIndex($expiringConnection);

        expect(fn (): array => $expiringStates->pendingQueueEntries([
            'connection' => 'redis',
            'queue' => 'default',
        ]))->toThrow(
            RuntimeException::class,
            'snapshot expired; refresh is required',
        );

        $remainingKeys = $rawRedis->keys('*');
        $remainingKeys = is_array($remainingKeys) ? array_map(strval(...), $remainingKeys) : [];
        $remainingExpiredKeys = array_values(array_filter(
            $remainingKeys,
            static fn (string $key): bool => str_contains($key, ':horizon-new-dawn:pending-queue-snapshot:'),
        ));

        expect($remainingExpiredKeys)->toBe([]);
    } finally {
        $rawRedis->flushdb();
    }
});

it('serializes failed-job retry claims through the configured real Redis client', function (): void {
    $environment = retainedJobRedisCompatibilityEnvironment();
    configureRetainedJobCompatibilityRedis($environment);

    $redis = app(RedisFactory::class);
    $rawRedis = $redis->connection('retained_job_compatibility_raw');
    $rawRedis->flushdb();

    try {
        $lock = new FailedJobRetryLock($redis);
        $nestedResult = true;
        $outerResult = $lock->run(
            'failed-compatibility-job',
            function () use ($lock, &$nestedResult): bool {
                $nestedResult = $lock->run(
                    'failed-compatibility-job',
                    static fn (): bool => true,
                );

                return true;
            },
        );
        $afterRelease = $lock->run(
            'failed-compatibility-job',
            static fn (): bool => true,
        );
        $remainingLocks = array_values(array_filter(
            $rawRedis->keys('*'),
            static fn (string $key): bool => str_contains(
                $key,
                'failed-job-retry-lock',
            ),
        ));

        expect($outerResult)->toBeTrue()
            ->and($nestedResult)->toBeFalse()
            ->and($afterRelease)->toBeTrue()
            ->and($remainingLocks)->toBe([]);
    } finally {
        $rawRedis->flushdb();
    }
});

/** @return array<string, string> */
function retainedJobRedisCompatibilityEnvironment(): array
{
    $host = getenv('HORIZON_COMPATIBILITY_REDIS_HOST');
    $port = getenv('HORIZON_COMPATIBILITY_REDIS_PORT');
    $database = getenv('HORIZON_COMPATIBILITY_REDIS_DB');
    $client = getenv('HORIZON_COMPATIBILITY_REDIS_CLIENT');

    if (! is_string($host) || ! in_array($host, ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException(
            'Set HORIZON_COMPATIBILITY_REDIS_HOST to localhost or 127.0.0.1.',
        );
    }

    if (! is_string($port) || filter_var($port, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException(
            'Set HORIZON_COMPATIBILITY_REDIS_PORT to an isolated local Redis port.',
        );
    }

    if (! is_string($database) || filter_var($database, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException(
            'Set HORIZON_COMPATIBILITY_REDIS_DB to an isolated Redis database.',
        );
    }

    if (! is_string($client) || $client === '') {
        $client = 'predis';
    }

    if (! in_array($client, ['predis', 'phpredis'], true)) {
        throw new RuntimeException(
            'Set HORIZON_COMPATIBILITY_REDIS_CLIENT to predis or phpredis.',
        );
    }

    $token = bin2hex(random_bytes(6));

    return [
        'HORIZON_PREFIX' => "horizon:new-dawn:retained-index:{$token}:",
        'REDIS_CLIENT' => $client,
        'REDIS_DB' => $database,
        'REDIS_HOST' => $host,
        'REDIS_PORT' => $port,
        'REDIS_PREFIX' => "database:new-dawn:compatibility:{$token}:",
    ];
}

/** @param array<string, string> $environment */
function configureRetainedJobCompatibilityRedis(array $environment): void
{
    $connection = [
        'host' => $environment['REDIS_HOST'],
        'password' => null,
        'port' => $environment['REDIS_PORT'],
        'database' => $environment['REDIS_DB'],
    ];

    config([
        'database.redis.client' => $environment['REDIS_CLIENT'],
        'database.redis.options.prefix' => $environment['REDIS_PREFIX'],
        'database.redis.default' => $connection,
        'database.redis.retained_job_compatibility_raw' => [
            ...$connection,
            'options' => ['prefix' => ''],
        ],
        'horizon.prefix' => $environment['HORIZON_PREFIX'],
        'horizon.use' => 'default',
    ]);

    Horizon::use('default');
    app()->forgetInstance('redis');
}

function pendingSnapshotCompatibilityStateIndex(
    Connection $connection,
): PendingJobStateIndex {
    $redis = mockDashboardContract(RedisFactory::class);
    dashboardReturns($redis, 'connection', $connection);
    $queue = new RedisQueue($redis, 'default', 'default');
    $queues = mockDashboardContract(QueueFactory::class);
    dashboardReturns($queues, 'connection', $queue);

    return new PendingJobStateIndex($queues);
}

/** @return array<int, string> */
function seedRetainedJobCompatibilityJobs(Connection $redis): array
{
    $matchingIds = [];
    $retainedAt = microtime(true);

    foreach (range(1, 120) as $position) {
        $matches = ($position - 1) % 8 < 5;
        $id = sprintf('retained-compatibility-%03d', $position);
        $name = $matches
            ? 'App\\Jobs\\GenerateCompatibilityReport'
            : 'App\\Jobs\\CleanupCompatibilityReport';
        $queue = $matches ? 'retained-reports' : 'maintenance';
        $connection = $matches ? 'redis-analytics' : 'redis';

        // Horizon completed_jobs scores are -timestamp: lower scores are newer and
        // appear first via zrange. Keep lower positions newest so matchingIds order
        // matches first-page results after filters.
        $redis->zadd(
            RetainedJobType::Completed->sourceKey(),
            -($retainedAt + ((121 - $position) / 1000)),
            $id,
        );
        $redis->hmset($id, [
            'id' => $id,
            'connection' => $connection,
            'queue' => $queue,
            'name' => $name,
            'status' => 'completed',
            'payload' => json_encode([
                'displayName' => $name,
                'tags' => ["compatibility:{$position}"],
            ], JSON_THROW_ON_ERROR),
            'completed_at' => (string) $position,
        ]);

        if ($matches) {
            $matchingIds[] = $id;
        }
    }

    return $matchingIds;
}
