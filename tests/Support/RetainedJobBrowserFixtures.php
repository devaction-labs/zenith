<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Tests\Support;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\TagRepository;
use NckRtl\HorizonNewDawn\FailedJobs\FailedJobRetryEligibility;
use NckRtl\HorizonNewDawn\FailedJobs\FailedJobsData;
use NckRtl\HorizonNewDawn\Jobs\JobsData;
use NckRtl\HorizonNewDawn\Jobs\PendingJobStateIndex;
use NckRtl\HorizonNewDawn\Jobs\RetainedJobFilterCatalog;
use NckRtl\HorizonNewDawn\Jobs\RetainedJobIndex;
use NckRtl\HorizonNewDawn\Jobs\RetainedJobQuery;
use NckRtl\HorizonNewDawn\Jobs\RetainedJobType;
use NckRtl\HorizonNewDawn\Metrics\SnapshotJobsPerMinute;
use NckRtl\HorizonNewDawn\Queues\QueueActivityData;
use NckRtl\HorizonNewDawn\Queues\QueueBatchesData;
use NckRtl\HorizonNewDawn\Queues\QueueJobsData;
use NckRtl\HorizonNewDawn\Queues\QueueSummary;

final class RetainedJobBrowserRedisConnection extends Connection
{
    /** @var array<string, array<string, float>> */
    public array $sortedSets = [];

    /** @var array<string, array<string, string>> */
    private array $hashes = [];

    /** @var array<string, array<string, true>> */
    private array $sets = [];

    /** @var array<string, array<int, string>> */
    public array $lists = [];

    /** @var array<string, string> */
    private array $strings = [];

    private bool $recordPipelineResults = false;

    /** @var array<int, mixed> */
    private array $pipelineResults = [];

    /** @param array<string, int|float> $members */
    public function seedSortedSet(string $key, array $members): void
    {
        $this->sortedSets[$key] = array_map(
            static fn (int|float $score): float => (float) $score,
            $members,
        );
    }

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}

    public function client(): self
    {
        return $this;
    }

    /** @return array<int|string, float|string> */
    public function zrange(string $key, int $start, int $stop, mixed $options = null): array
    {
        return $this->sortedSetRange($key, $start, $stop, $options);
    }

    /** @return array<int|string, float|string> */
    public function zrevrange(string $key, int $start, int $stop, mixed $options = null): array
    {
        return $this->sortedSetRange($key, $start, $stop, $options, reverse: true);
    }

    /**
     * @param  array{limit?: array{offset?: int, count?: int}}|int  $options
     * @return array<int, string>
     */
    public function zrangebylex(
        string $key,
        string $minimum,
        string $maximum,
        array|int $options = [],
        ?int $count = null,
    ): array {
        $options = is_int($options)
            ? ['limit' => ['offset' => $options, 'count' => $count ?? 0]]
            : $options;

        return $this->sortedSetLexRange(
            $key,
            $minimum,
            $maximum,
            $options,
        );
    }

    /**
     * @param  array{limit?: array{offset?: int, count?: int}}|int  $options
     * @return array<int, string>
     */
    public function zrevrangebylex(
        string $key,
        string $maximum,
        string $minimum,
        array|int $options = [],
        ?int $count = null,
    ): array {
        $options = is_int($options)
            ? ['limit' => ['offset' => $options, 'count' => $count ?? 0]]
            : $options;

        return $this->sortedSetLexRange(
            $key,
            $minimum,
            $maximum,
            $options,
            reverse: true,
        );
    }

    public function zadd(string $key, float|int|string $score, string $member): int
    {
        $created = ! isset($this->sortedSets[$key][$member]);
        $this->sortedSets[$key][$member] = (float) $score;

        return $created ? 1 : 0;
    }

    public function zrem(string $key, string ...$members): int
    {
        $removed = 0;

        foreach ($members as $member) {
            if (isset($this->sortedSets[$key][$member])) {
                $removed++;
            }

            unset($this->sortedSets[$key][$member]);
        }

        return $removed;
    }

    public function zscore(string $key, string $member): float|false
    {
        $score = $this->sortedSets[$key][$member] ?? false;

        if ($this->recordPipelineResults) {
            $this->pipelineResults[] = $score;
        }

        return $score;
    }

    public function zcard(string $key): int
    {
        $count = count($this->sortedSets[$key] ?? []);

        if ($this->recordPipelineResults) {
            $this->pipelineResults[] = $count;
        }

        return $count;
    }

    public function zcount(string $key, float|int|string $minimum, float|int|string $maximum): int
    {
        $minimumExclusive = is_string($minimum) && str_starts_with($minimum, '(');
        $maximumExclusive = is_string($maximum) && str_starts_with($maximum, '(');
        $normalizedMinimum = $minimumExclusive ? substr($minimum, 1) : $minimum;
        $normalizedMaximum = $maximumExclusive ? substr($maximum, 1) : $maximum;

        return count(array_filter(
            $this->sortedSets[$key] ?? [],
            static fn (float $score): bool => ($normalizedMinimum === '-inf'
                || ($minimumExclusive
                    ? $score > (float) $normalizedMinimum
                    : $score >= (float) $normalizedMinimum))
                && ($normalizedMaximum === '+inf'
                    || ($maximumExclusive
                        ? $score < (float) $normalizedMaximum
                        : $score <= (float) $normalizedMaximum)),
        ));
    }

    /** @param array<int, string> $keys */
    public function zdiffstore(string $destination, array $keys): int
    {
        $members = $this->sortedSets[$keys[0]] ?? [];

        foreach (array_slice($keys, 1) as $key) {
            $members = array_diff_key($members, $this->sortedSets[$key] ?? []);
        }

        $this->sortedSets[$destination] = $members;

        return count($members);
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, int>  $weights
     */
    public function zinterstore(
        string $destination,
        array $keys,
        array $weights = [],
        string $aggregate = 'sum',
    ): int {
        $members = $this->sortedSets[$keys[0]] ?? [];

        foreach (array_slice($keys, 1) as $key) {
            $members = array_intersect_key($members, $this->sortedSets[$key] ?? []);
        }

        foreach ($members as $member => $score) {
            $weightedScore = 0.0;

            foreach ($keys as $index => $key) {
                $weightedScore += ($this->sortedSets[$key][$member] ?? 0)
                    * ($weights[$index] ?? 1);
            }

            $members[$member] = $weightedScore;
        }

        $this->sortedSets[$destination] = $members;

        return count($members);
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, int>  $weights
     */
    public function zunionstore(
        string $destination,
        array $keys,
        array $weights = [],
        string $aggregate = 'sum',
    ): int {
        $members = [];

        foreach ($keys as $index => $key) {
            foreach ($this->sortedSets[$key] ?? [] as $member => $score) {
                $weightedScore = $score * ($weights[$index] ?? 1);
                $members[$member] = ($members[$member] ?? 0)
                    + $weightedScore;
            }
        }

        $this->sortedSets[$destination] = $members;

        return count($members);
    }

    public function hset(string $key, string $field, string $value): int
    {
        $created = ! isset($this->hashes[$key][$field]);
        $this->hashes[$key][$field] = $value;

        return $created ? 1 : 0;
    }

    public function hget(string $key, string $field): string|false
    {
        return $this->hashes[$key][$field] ?? false;
    }

    public function hlen(string $key): int
    {
        return count($this->hashes[$key] ?? []);
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<int, string|false>
     */
    public function hmget(string $key, array $fields): array
    {
        return array_map(
            fn (string $field): string|false => $this->hashes[$key][$field] ?? false,
            $fields,
        );
    }

    public function hdel(string $key, string $field): int
    {
        $exists = isset($this->hashes[$key][$field]);
        unset($this->hashes[$key][$field]);

        return $exists ? 1 : 0;
    }

    public function sadd(string $key, string $value): int
    {
        $created = ! isset($this->sets[$key][$value]);
        $this->sets[$key][$value] = true;

        return $created ? 1 : 0;
    }

    /** @return array<int, string> */
    public function smembers(string $key): array
    {
        return array_keys($this->sets[$key] ?? []);
    }

    /**
     * @param  array{count?: int}  $options
     * @return array{int, array<int, string>}|false
     */
    public function sscan(
        string $key,
        int|string $cursor,
        array $options = [],
    ): array|false {
        if ((string) $cursor !== '0') {
            return false;
        }

        $members = array_keys($this->sets[$key] ?? []);

        return $members === [] ? false : [0, $members];
    }

    public function srem(string $key, string $value): int
    {
        $exists = isset($this->sets[$key][$value]);
        unset($this->sets[$key][$value]);

        return $exists ? 1 : 0;
    }

    public function scard(string $key): int
    {
        return count($this->sets[$key] ?? []);
    }

    public function sismember(string $key, string $value): bool
    {
        return isset($this->sets[$key][$value]);
    }

    public function expire(string $key, int $seconds): bool
    {
        return true;
    }

    public function del(string ...$keys): int
    {
        $removed = 0;

        foreach ($keys as $key) {
            $exists = isset($this->sortedSets[$key])
                || isset($this->sets[$key])
                || isset($this->hashes[$key])
                || isset($this->lists[$key])
                || isset($this->strings[$key]);
            unset(
                $this->sortedSets[$key],
                $this->sets[$key],
                $this->hashes[$key],
                $this->lists[$key],
                $this->strings[$key],
            );
            $removed += $exists ? 1 : 0;
        }

        return $removed;
    }

    public function setnx(string $key, string $value): int
    {
        if (isset($this->strings[$key])) {
            return 0;
        }

        $this->strings[$key] = $value;

        return 1;
    }

    public function get(string $key): string|false
    {
        return $this->strings[$key] ?? false;
    }

    public function eval(
        string $script,
        int $keyCount,
        string ...$parameters,
    ): mixed {
        $keys = array_values(array_slice($parameters, 0, $keyCount));
        $arguments = array_values(array_slice($parameters, $keyCount));

        if (str_contains($script, "local timestamp = redis.call('time')")) {
            return $this->createPendingSnapshot($keys, $arguments);
        }

        if (str_contains($script, 'guard ~= ARGV[4]')) {
            return $this->readPendingSnapshot($keys, $arguments);
        }

        $key = $keys[0] ?? '';
        $token = $arguments[0] ?? '';

        if (str_contains($script, 'local boundary')) {
            return $this->strings[$key] ?? false;
        }

        if (($this->strings[$key] ?? null) !== $token) {
            return 0;
        }

        if (str_contains($script, "redis.call('expire'")) {
            return 1;
        }

        unset($this->strings[$key]);

        return 1;
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, string>  $arguments
     * @return array{string, string, string, string}
     */
    private function createPendingSnapshot(
        array $keys,
        array $arguments,
    ): array {
        [$guardKey, $listSource, $sortedSource, $listKey, $sortedKey] = $keys;
        $state = $arguments[0];
        $asOf = (string) time();
        unset($this->lists[$listKey], $this->sortedSets[$sortedKey]);

        if (in_array($state, ['ready', 'released'], true)) {
            $payloads = $this->lists[$listSource] ?? [];

            if ($payloads !== []) {
                $this->lists[$listKey] = $payloads;
            }
        }

        if ($state === 'reserved') {
            $members = $this->sortedSets[$sortedSource] ?? [];

            if ($members !== []) {
                $this->sortedSets[$sortedKey] = $members;
            }
        } elseif (in_array($state, ['delayed', 'released'], true)) {
            $members = array_filter(
                $this->sortedSets[$sortedSource] ?? [],
                static fn (float $score): bool => $state === 'delayed'
                    ? $score > (float) $asOf
                    : $score <= (float) $asOf,
            );

            if ($members !== []) {
                $this->sortedSets[$sortedKey] = $members;
            }
        }

        $listCount = count($this->lists[$listKey] ?? []);
        $sortedCount = count($this->sortedSets[$sortedKey] ?? []);
        $guard = "{$asOf}:{$listCount}:{$sortedCount}";
        $this->strings[$guardKey] = $guard;

        return [
            $asOf,
            (string) $listCount,
            (string) $sortedCount,
            $guard,
        ];
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, string>  $arguments
     * @return array{string, array<int|string, float|string>}|false
     */
    private function readPendingSnapshot(
        array $keys,
        array $arguments,
    ): array|false {
        [$guardKey, $key, $listKey, $sortedKey] = $keys;
        [$start, $stop, $kind, $expectedGuard] = $arguments;
        $guard = $this->strings[$guardKey] ?? null;

        if ($guard !== $expectedGuard) {
            return false;
        }

        if ($kind === 'list') {
            $payloads = $this->lrange($key, (int) $start, (int) $stop);
        } elseif ($kind === 'sorted_scores') {
            $payloads = $this->zrange($key, (int) $start, (int) $stop, [
                'withscores' => true,
            ]);
        } else {
            $payloads = array_values(array_filter(
                $this->zrange($key, (int) $start, (int) $stop),
                is_string(...),
            ));
        }

        return [$guard, $payloads];
    }

    public function set(
        string $key,
        string $value,
        mixed ...$arguments,
    ): bool {
        if (
            in_array('NX', $arguments, true)
            && isset($this->strings[$key])
        ) {
            return false;
        }

        $this->strings[$key] = $value;

        return true;
    }

    /** @return array<int, string> */
    public function lrange(string $key, int $start, int $stop): array
    {
        $length = $stop < 0 ? null : max(0, $stop - $start + 1);

        return array_slice($this->lists[$key] ?? [], $start, $length);
    }

    /** @return array<int, mixed> */
    public function pipeline(Closure $callback): array
    {
        $this->recordPipelineResults = true;
        $this->pipelineResults = [];
        $callback($this);
        $this->recordPipelineResults = false;

        return $this->pipelineResults;
    }

    /** @return array<int, mixed> */
    public function transaction(Closure $callback): array
    {
        $callback($this);

        return [];
    }

    /** @return array<int|string, float|string> */
    private function sortedSetRange(
        string $key,
        int $start,
        int $stop,
        mixed $options,
        bool $reverse = false,
    ): array {
        $members = $this->sortedSets[$key] ?? [];
        uksort(
            $members,
            static function (string $left, string $right) use ($members): int {
                $scoreComparison = $members[$left] <=> $members[$right];

                return $scoreComparison !== 0 ? $scoreComparison : strcmp($left, $right);
            },
        );

        if ($reverse) {
            $members = array_reverse($members, true);
        }

        $length = $stop < 0 ? null : max(0, $stop - $start + 1);
        $slice = array_slice($members, $start, $length, true);
        $withScores = $options === true
            || (is_array($options) && ($options['withscores'] ?? false) === true);

        return $withScores ? $slice : array_keys($slice);
    }

    /**
     * @param  array{limit?: array{offset?: int, count?: int}}  $options
     * @return array<int, string>
     */
    private function sortedSetLexRange(
        string $key,
        string $minimum,
        string $maximum,
        array $options,
        bool $reverse = false,
    ): array {
        $members = array_keys($this->sortedSets[$key] ?? []);
        sort($members, SORT_STRING);
        $members = array_values(array_filter(
            $members,
            fn (string $member): bool => $this->matchesLexMinimum(
                $member,
                $minimum,
            ) && $this->matchesLexMaximum($member, $maximum),
        ));

        if ($reverse) {
            $members = array_reverse($members);
        }

        $offset = max(0, $options['limit']['offset'] ?? 0);
        $count = max(0, $options['limit']['count'] ?? count($members));

        return array_slice($members, $offset, $count);
    }

    private function matchesLexMinimum(string $member, string $minimum): bool
    {
        if ($minimum === '-') {
            return true;
        }

        $comparison = strcmp($member, substr($minimum, 1));

        return str_starts_with($minimum, '(')
            ? $comparison > 0
            : $comparison >= 0;
    }

    private function matchesLexMaximum(string $member, string $maximum): bool
    {
        if ($maximum === '+') {
            return true;
        }

        $comparison = strcmp($member, substr($maximum, 1));

        return str_starts_with($maximum, '(')
            ? $comparison < 0
            : $comparison <= 0;
    }
}

function bindRetainedJobBrowserFixtures(
    int $jobCount = 110,
    int $matchingIndex = 105,
    RetainedJobType $type = RetainedJobType::Pending,
    bool $allMatching = false,
    bool $allInQueue = false,
): string {
    bindBrowserPageFixtures();

    $matchingId = sprintf('%s-%03d', $type->value, $matchingIndex);
    $source = [];

    foreach (range(0, $jobCount - 1) as $index) {
        $source[sprintf('%s-%03d', $type->value, $index)] = (float) -($index + 1);
    }

    $horizonRedisConnection = new RetainedJobBrowserRedisConnection;
    $horizonRedisConnection->seedSortedSet($type->sourceKey(), $source);

    if (
        $type === RetainedJobType::Failed
        && isset($source[$matchingId])
    ) {
        $horizonRedisConnection->seedSortedSet('failed:tenant:production', [
            $matchingId => $source[$matchingId],
        ]);
    }

    $horizonRedis = mockDashboardContract(RedisFactory::class);
    dashboardReturns(
        $horizonRedis,
        'connection',
        $horizonRedisConnection,
    );

    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturns($jobs, 'trimRecentJobs', null);
    dashboardReturns($jobs, 'trimFailedJobs', null);
    $hydrate = static fn (array $ids, int $startingAt = 0): Collection => new Collection(array_map(
        static function (string $id) use (
            $matchingId,
            $type,
            $allMatching,
            $allInQueue,
        ): HorizonJob {
            $index = (int) substr($id, strrpos($id, '-') + 1);
            $job = horizonJob($index, $id);
            $isMatching = $allMatching || $id === $matchingId;
            $job->name = $isMatching
                ? 'App\\Jobs\\ProductionOnly'
                : 'App\\Jobs\\RoutineImport';
            $job->queue = $allInQueue || $isMatching ? 'reports' : 'default';
            $job->connection = $isMatching ? 'redis-secondary' : 'redis';
            $job->status = match ($type) {
                RetainedJobType::Pending => $isMatching ? 'reserved' : 'pending',
                RetainedJobType::Failed => 'failed',
                default => 'completed',
            };
            $job->completed_at = in_array(
                $type,
                [RetainedJobType::Completed, RetainedJobType::Silenced],
                true,
            )
                ? $job->completed_at
                : null;
            $job->failed_at = $type === RetainedJobType::Failed
                ? (string) (1_784_281_100.25 + $index)
                : null;
            $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
            $job->payload = json_encode([
                ...$payload,
                'uuid' => $id,
                'displayName' => $job->name,
                'pushedAt' => 1_784_281_000.25 + $index,
                'tags' => $isMatching
                    ? ['tenant:production', 'import']
                    : ['tenant:1', 'import'],
            ], JSON_THROW_ON_ERROR);

            return $job;
        },
        $ids,
    ));
    dashboardReturnsUsing(
        $jobs,
        'getJobs',
        $hydrate,
    );
    $sourceIds = array_keys($source);
    $repositoryPage = static function (int|string|null $afterIndex = null) use (
        $hydrate,
        $sourceIds,
    ): Collection {
        $start = is_numeric($afterIndex) ? (int) $afterIndex + 1 : 0;
        $ids = array_slice($sourceIds, $start, 50);

        return $hydrate($ids, $start);
    };
    $repositoryMethod = match ($type) {
        RetainedJobType::Pending => 'getPending',
        RetainedJobType::Completed => 'getCompleted',
        RetainedJobType::Silenced => 'getSilenced',
        RetainedJobType::Failed => 'getFailed',
    };
    dashboardReturnsUsing($jobs, $repositoryMethod, $repositoryPage);
    dashboardReturnsUsing(
        $jobs,
        match ($type) {
            RetainedJobType::Pending => 'countPending',
            RetainedJobType::Completed => 'countCompleted',
            RetainedJobType::Silenced => 'countSilenced',
            RetainedJobType::Failed => 'countFailed',
        },
        static fn (): int => $jobCount,
    );
    app()->instance(JobRepository::class, $jobs);

    $queueRedisConnection = new RetainedJobBrowserRedisConnection;
    $queueRedisConnection->seedSortedSet('queues:reports:reserved', [
        json_encode(['uuid' => $matchingId], JSON_THROW_ON_ERROR) => 1_784_282_000,
    ]);
    $queueRedis = mockDashboardContract(RedisFactory::class);
    dashboardReturns(
        $queueRedis,
        'connection',
        $queueRedisConnection,
    );
    $queue = new RedisQueue($queueRedis, 'default', 'default');
    $queues = mockDashboardContract(QueueFactory::class);
    dashboardReturns($queues, 'connection', $queue);
    app()->instance(QueueFactory::class, $queues);

    $index = new RetainedJobIndex($horizonRedis, $jobs);
    $query = new RetainedJobQuery(
        $jobs,
        $index,
        new PendingJobStateIndex($queues),
    );
    $query->refreshPublishedIndex($type);
    $catalog = new RetainedJobFilterCatalog($index);
    app()->instance(JobsData::class, new JobsData(
        jobs: $jobs,
        retainedQuery: $query,
        filterCatalog: $catalog,
    ));

    if ($type === RetainedJobType::Failed) {
        $tags = mockDashboardContract(TagRepository::class);
        dashboardReturnsUsing(
            $tags,
            'count',
            static fn (string $tag): int => $tag === 'failed:tenant:production' ? 1 : 0,
        );
        app()->instance(FailedJobsData::class, new FailedJobsData(
            repository: $jobs,
            tags: $tags,
            jobs: app(JobsData::class),
            retryEligibility: new FailedJobRetryEligibility,
            redis: $horizonRedis,
            retainedQuery: $query,
            filterCatalog: $catalog,
        ));
    }

    $queueJobs = new QueueJobsData(
        repository: $jobs,
        jobs: app(JobsData::class),
        failedJobs: app(FailedJobsData::class),
        cache: app(CacheFactory::class),
        retainedQuery: $query,
    );
    $queueBatches = app(QueueBatchesData::class);
    app()->instance(QueueJobsData::class, $queueJobs);
    app()->instance(QueueSummary::class, new QueueSummary(
        $queueJobs,
        $queueBatches,
        app(MetricsRepository::class),
        app(SnapshotJobsPerMinute::class),
    ));
    app()->instance(QueueActivityData::class, new QueueActivityData($queueJobs, $queueBatches));

    return $matchingId;
}
