<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobRetryEligibility;
use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobsData;
use DevactionLabs\HorizonNewDawn\Jobs\Data\JobIndexFiltersData;
use DevactionLabs\HorizonNewDawn\Jobs\JobListType;
use DevactionLabs\HorizonNewDawn\Jobs\JobsData;
use DevactionLabs\HorizonNewDawn\Jobs\PendingJobStateIndex;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobFilterCatalog;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobIndex;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobIndexWarming;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobQuery;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobType;
use DevactionLabs\HorizonNewDawn\Queues\QueueActivityTab;
use DevactionLabs\HorizonNewDawn\Queues\QueueJobsData;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Date;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Mockery\MockInterface;
use Predis\Client;

use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturns;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\HorizonNewDawn\Tests\Support\horizonJob;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

final class RetainedJobQueryRedisClient extends Client
{
    /** @var array<string, array<string, float>> */
    public array $sortedSets = [];

    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    /** @var array<string, array<string, true>> */
    public array $sets = [];

    /** @var array<string, array<int, string>> */
    public array $lists = [];

    /** @var array<string, string> */
    public array $strings = [];

    /** @var array<int, array<int, string>> */
    public array $metadataReads = [];

    /** @var array<int, string> */
    public array $expiredKeys = [];

    /** @var array<int, string> */
    public array $deletedKeys = [];

    /** @var array<int, array{key: string, min: string, max: string}> */
    public array $scoreRanges = [];

    public int $intersectionWrites = 0;

    public int $unionWrites = 0;

    public int $searchUnionWrites = 0;

    public int $lexRangeCalls = 0;

    public int $maxPipelineResults = 0;

    public int $setMemberReads = 0;

    public bool $failNextPendingSnapshotCreation = false;

    /** @var (Closure(self, string, array<int, string>): void)|null */
    public ?Closure $afterNextDifferenceStore = null;

    /** @var (Closure(self, string, array<int, string>): void)|null */
    public ?Closure $afterSourceSnapshotStore = null;

    /** @var (Closure(self, string, array<int, string>): void)|null */
    public ?Closure $afterFinalSourceDeltaStore = null;

    /** @var (Closure(self, string): void)|null */
    public ?Closure $beforeNextLexicographicRead = null;

    /** @var (Closure(self, string): void)|null */
    public ?Closure $afterNextLexicographicRead = null;

    /** @var (Closure(self, string): void)|null */
    public ?Closure $afterNextListRange = null;

    /** @var (Closure(self, string): void)|null */
    public ?Closure $afterNextSortedRange = null;

    private bool $recordPipelineResults = false;

    /** @var array<int, mixed> */
    private array $pipelineResults = [];

    public function __construct()
    {
        parent::__construct(options: ['prefix' => '']);
    }

    /** @param array<string, float|int> $members */
    public function seedSortedSet(string $key, array $members): void
    {
        $this->sortedSets[$key] = array_map(
            static fn (float|int $score): float => (float) $score,
            $members,
        );
    }

    /** @return array<int|string, float|string> */
    public function zrange(string $key, int $start, int $stop, mixed $options = null): array
    {
        $members = $this->sortedSetRange(
            $key,
            $start,
            $stop,
            $options,
            false,
        );
        $afterRead = $this->afterNextSortedRange;
        $this->afterNextSortedRange = null;

        if ($afterRead instanceof Closure) {
            $afterRead($this, $key);
        }

        return $members;
    }

    /** @return array<int|string, float|string> */
    public function zrevrange(string $key, int $start, int $stop, mixed $options = null): array
    {
        return $this->sortedSetRange($key, $start, $stop, $options, true);
    }

    /**
     * @param  array{limit?: array{offset?: int, count?: int, 0?: int, 1?: int}}  $options
     * @return array<int, string>
     */
    public function zrangebylex(
        string $key,
        string $minimum,
        string $maximum,
        array $options = [],
    ): array {
        $this->lexRangeCalls++;
        $beforeRead = $this->beforeNextLexicographicRead;
        $this->beforeNextLexicographicRead = null;

        if ($beforeRead instanceof Closure) {
            $beforeRead($this, $key);
        }

        $members = $this->sortedSetLexRange(
            $key,
            $minimum,
            $maximum,
            $options,
            false,
        );
        $afterRead = $this->afterNextLexicographicRead;
        $this->afterNextLexicographicRead = null;

        if ($afterRead instanceof Closure) {
            $afterRead($this, $key);
        }

        return $members;
    }

    /**
     * @param  array{limit?: array{offset?: int, count?: int, 0?: int, 1?: int}}  $options
     * @return array<int, string>
     */
    public function zrevrangebylex(
        string $key,
        string $maximum,
        string $minimum,
        array $options = [],
    ): array {
        $this->lexRangeCalls++;
        $beforeRead = $this->beforeNextLexicographicRead;
        $this->beforeNextLexicographicRead = null;

        if ($beforeRead instanceof Closure) {
            $beforeRead($this, $key);
        }

        $members = $this->sortedSetLexRange(
            $key,
            $minimum,
            $maximum,
            $options,
            true,
        );
        $afterRead = $this->afterNextLexicographicRead;
        $this->afterNextLexicographicRead = null;

        if ($afterRead instanceof Closure) {
            $afterRead($this, $key);
        }

        return $members;
    }

    /**
     * @param  array{
     *     limit?: array{offset?: int, count?: int, 0?: int, 1?: int},
     *     withscores?: bool
     * }  $options
     * @return array<int|string, float|string>
     */
    public function zrangebyscore(
        string $key,
        float|int|string $min,
        float|int|string $max,
        array $options = [],
    ): array {
        $this->scoreRanges[] = [
            'key' => $key,
            'min' => (string) $min,
            'max' => (string) $max,
        ];
        $minimumExclusive = is_string($min) && str_starts_with($min, '(');
        $maximumExclusive = is_string($max) && str_starts_with($max, '(');
        $minimum = $minimumExclusive ? substr($min, 1) : $min;
        $maximum = $maximumExclusive ? substr($max, 1) : $max;
        $members = array_filter(
            $this->sortedSets[$key] ?? [],
            static fn (float $score): bool => ($minimum === '-inf'
                || ($minimumExclusive ? $score > (float) $minimum : $score >= (float) $minimum))
                && ($maximum === '+inf'
                    || ($maximumExclusive ? $score < (float) $maximum : $score <= (float) $maximum)),
        );
        uksort(
            $members,
            static function (string $left, string $right) use ($members): int {
                $score = $members[$left] <=> $members[$right];

                return $score !== 0 ? $score : strcmp($left, $right);
            },
        );
        $limit = $options['limit'] ?? [0, count($members)];
        $members = array_slice(
            $members,
            (int) ($limit['offset'] ?? $limit[0] ?? 0),
            (int) ($limit['count'] ?? $limit[1] ?? count($members)),
            true,
        );

        $result = ($options['withscores'] ?? false) === true
            ? $members
            : array_keys($members);
        $afterRead = $this->afterNextSortedRange;
        $this->afterNextSortedRange = null;

        if ($afterRead instanceof Closure) {
            $afterRead($this, $key);
        }

        return $result;
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

    public function zcount(string $key, float|int|string $min, float|int|string $max): int
    {
        $minimumExclusive = is_string($min) && str_starts_with($min, '(');
        $maximumExclusive = is_string($max) && str_starts_with($max, '(');
        $minimum = $minimumExclusive ? substr($min, 1) : $min;
        $maximum = $maximumExclusive ? substr($max, 1) : $max;

        return count(array_filter(
            $this->sortedSets[$key] ?? [],
            static fn (float $score): bool => ($minimum === '-inf'
                || ($minimumExclusive ? $score > (float) $minimum : $score >= (float) $minimum))
                && ($maximum === '+inf'
                    || ($maximumExclusive ? $score < (float) $maximum : $score <= (float) $maximum)),
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
        $afterDifferenceStore = $this->afterNextDifferenceStore;
        $this->afterNextDifferenceStore = null;

        if ($afterDifferenceStore instanceof Closure) {
            $afterDifferenceStore($this, $destination, $keys);
        }

        if (
            str_contains($destination, ':temporary:retained-source-additions:')
            && $this->afterFinalSourceDeltaStore instanceof Closure
        ) {
            $afterFinalSourceDeltaStore = $this->afterFinalSourceDeltaStore;
            $this->afterFinalSourceDeltaStore = null;
            $afterFinalSourceDeltaStore($this, $destination, $keys);
        }

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
        $this->intersectionWrites++;
        $members = $this->sortedSets[$keys[0]] ?? [];

        foreach (array_slice($keys, 1) as $key) {
            $members = array_intersect_key($members, $this->sortedSets[$key] ?? []);
        }

        foreach ($members as $member => $score) {
            $weighted = 0.0;

            foreach ($keys as $index => $key) {
                $weighted += ($this->sortedSets[$key][$member] ?? 0)
                    * ($weights[$index] ?? 1);
            }

            $members[$member] = $weighted;
        }

        $this->sortedSets[$destination] = $members;

        if (
            count($keys) === 1
            && $this->afterSourceSnapshotStore instanceof Closure
        ) {
            ($this->afterSourceSnapshotStore)($this, $destination, $keys);
        }

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
        $this->unionWrites++;

        if (str_contains($destination, ':temporary:search:')) {
            $this->searchUnionWrites++;
        }

        $members = [];

        foreach ($keys as $index => $key) {
            foreach ($this->sortedSets[$key] ?? [] as $member => $score) {
                $weighted = $score * ($weights[$index] ?? 1);
                $members[$member] = ($members[$member] ?? 0) + $weighted;
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
        $this->metadataReads[] = $fields;

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
        $this->setMemberReads++;

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
        $this->expiredKeys[] = $key;

        return true;
    }

    public function del(string ...$keys): int
    {
        $removed = 0;

        foreach ($keys as $key) {
            $this->deletedKeys[] = $key;
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
            $result = $this->createPendingSnapshot($keys, $arguments);

            if ($this->failNextPendingSnapshotCreation) {
                $this->failNextPendingSnapshotCreation = false;

                throw new RuntimeException(
                    'The pending job snapshot creation failed.',
                );
            }

            return $result;
        }

        if (
            str_contains(
                $script,
                'guard ~= ARGV[4]',
            )
        ) {
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
        $asOf = (string) Date::now()->getTimestamp();
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
            $this->scoreRanges[] = [
                'key' => $sortedSource,
                'min' => $state === 'delayed' ? "({$asOf}" : '-inf',
                'max' => $state === 'delayed' ? '+inf' : $asOf,
            ];
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
        $this->expiredKeys[] = $guardKey;

        if ($listCount > 0) {
            $this->expiredKeys[] = $listKey;
        }

        if ($sortedCount > 0) {
            $this->expiredKeys[] = $sortedKey;
        }

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
        $this->expiredKeys[] = $guardKey;

        if (isset($this->lists[$listKey])) {
            $this->expiredKeys[] = $listKey;
        }

        if (isset($this->sortedSets[$sortedKey])) {
            $this->expiredKeys[] = $sortedKey;
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
        $payloads = array_slice(
            $this->lists[$key] ?? [],
            $start,
            $length,
        );
        $afterRead = $this->afterNextListRange;
        $this->afterNextListRange = null;

        if ($afterRead instanceof Closure) {
            $afterRead($this, $key);
        }

        return $payloads;
    }

    /** @return array<int, mixed> */
    public function pipeline(...$arguments): array
    {
        $callback = $arguments[0] ?? null;
        $this->recordPipelineResults = true;
        $this->pipelineResults = [];

        if ($callback instanceof Closure) {
            $callback($this);
        }

        $this->recordPipelineResults = false;
        $this->maxPipelineResults = max(
            $this->maxPipelineResults,
            count($this->pipelineResults),
        );

        return $this->pipelineResults;
    }

    /** @return array<int, mixed> */
    public function transaction(...$arguments): array
    {
        $callback = $arguments[0] ?? null;

        if ($callback instanceof Closure) {
            $callback($this);
        }

        return [];
    }

    /** @return array<int|string, float|string> */
    private function sortedSetRange(
        string $key,
        int $start,
        int $stop,
        mixed $options,
        bool $reverse,
    ): array {
        $members = $this->sortedSets[$key] ?? [];
        uksort(
            $members,
            static function (string $left, string $right) use ($members): int {
                $score = $members[$left] <=> $members[$right];

                return $score !== 0 ? $score : strcmp($left, $right);
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
     * @param array{
     *     limit?: array{offset?: int, count?: int, 0?: int, 1?: int}
     * } $options
     * @return array<int, string>
     */
    private function sortedSetLexRange(
        string $key,
        string $minimum,
        string $maximum,
        array $options,
        bool $reverse,
    ): array {
        $members = array_keys($this->sortedSets[$key] ?? []);
        sort($members, SORT_STRING);
        $members = array_values(array_filter(
            $members,
            static function (string $member) use ($minimum, $maximum): bool {
                $aboveMinimum = $minimum === '-'
                    || ($minimum[0] === '('
                        ? strcmp($member, substr($minimum, 1)) > 0
                        : strcmp($member, substr($minimum, 1)) >= 0);
                $belowMaximum = $maximum === '+'
                    || ($maximum[0] === '('
                        ? strcmp($member, substr($maximum, 1)) < 0
                        : strcmp($member, substr($maximum, 1)) <= 0);

                return $aboveMinimum && $belowMaximum;
            },
        ));

        if ($reverse) {
            $members = array_reverse($members);
        }

        $limit = $options['limit'] ?? [0, count($members)];

        return array_slice(
            $members,
            (int) ($limit['offset'] ?? $limit[0] ?? 0),
            (int) ($limit['count'] ?? $limit[1] ?? count($members)),
        );
    }
}

final class ClusteredRetainedJobQueryConnection extends PredisConnection
{
    public function isCluster(): bool
    {
        return true;
    }
}

/**
 * @return array{
 *     query: RetainedJobQuery,
 *     catalog: RetainedJobFilterCatalog,
 *     index: RetainedJobIndex,
 *     repository: JobRepository&MockInterface,
 *     repositoryHydrations: array<int, array<int, string>>
 * }
 */
function retainedJobQueryFixture(
    RetainedJobQueryRedisClient $horizon,
    ?PendingJobStateIndex $pendingStates = null,
    ?Closure $jobFactory = null,
    ?Closure $trimRecentJobs = null,
): array {
    $hydrations = [];
    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsUsing(
        $repository,
        'getJobs',
        static function (array $requestedIds) use (
            &$hydrations,
            $jobFactory,
        ): Collection {
            $hydrations[] = $requestedIds;

            return new Collection(array_map(
                static function (
                    string $id,
                    int $position,
                ) use ($jobFactory): object {
                    if ($jobFactory !== null) {
                        return $jobFactory($id, $position);
                    }

                    $index = (int) str_replace(
                        ['pending-', 'completed-', 'failed-', 'silenced-', 'job-'],
                        '',
                        $id,
                    );
                    $job = horizonJob($position, $id);

                    if ($index === 75) {
                        $job->name = 'App\\Jobs\\ProductionOnly';
                        $job->queue = 'reports';
                        $job->connection = 'redis-secondary';
                        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
                        $job->payload = json_encode([
                            ...$payload,
                            'displayName' => $job->name,
                            'tags' => ['tenant:production'],
                        ], JSON_THROW_ON_ERROR);
                    }

                    if (str_starts_with($id, 'pending-')) {
                        $job->status = 'pending';
                        $job->completed_at = null;
                    } elseif (str_starts_with($id, 'failed-')) {
                        $job->status = 'failed';
                        $job->completed_at = null;
                        $job->failed_at = '1784281002.75';
                    }

                    return $job;
                },
                $requestedIds,
                array_keys($requestedIds),
            ));
        },
    );
    dashboardReturnsUsing(
        $repository,
        'trimRecentJobs',
        $trimRecentJobs ?? static fn (): null => null,
    );
    dashboardReturns($repository, 'trimFailedJobs', null);

    $redis = mockDashboardContract(RedisFactory::class);
    dashboardReturns($redis, 'connection', new PredisConnection($horizon));
    $index = new RetainedJobIndex(
        $redis,
        $repository,
    );

    return [
        'query' => new RetainedJobQuery($repository, $index, $pendingStates),
        'catalog' => new RetainedJobFilterCatalog($index),
        'index' => $index,
        'repository' => $repository,
        'repositoryHydrations' => &$hydrations,
    ];
}

function pendingJobStateIndexFor(
    RetainedJobQueryRedisClient $redis,
): PendingJobStateIndex {
    $redisFactory = mockDashboardContract(RedisFactory::class);
    dashboardReturns(
        $redisFactory,
        'connection',
        new PredisConnection($redis),
    );
    $queue = new RedisQueue($redisFactory, 'default', 'default');
    $queues = mockDashboardContract(QueueFactory::class);
    dashboardReturns($queues, 'connection', $queue);

    return new PendingJobStateIndex($queues);
}

/** @return array<string, float> */
function retainedSource(int $count, string $prefix): array
{
    $members = [];

    foreach (range(0, $count - 1) as $index) {
        $members["{$prefix}-{$index}"] = (float) -($index + 1);
    }

    return $members;
}

function retainedPublishedGenerationKey(
    RetainedJobQueryRedisClient $redis,
    RetainedJobType $type,
): string {
    foreach (array_keys($redis->strings) as $key) {
        if (str_ends_with($key, ":{$type->value}:published-generation")) {
            return $key;
        }
    }

    throw new RuntimeException("The {$type->value} generation was not published.");
}

describe('RetainedJobQuery', function (): void {
    afterEach(function (): void {
        Date::setTestNow();
    });

    it('applies class, queue, and connection filters before selecting 50 rows', function (
        JobIndexFiltersData $filters,
    ): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);

        $page = $fixture['query']->page(RetainedJobType::Completed, $filters, -1);

        expect($page->total)->toBe(1)
            ->and($page->jobs)->toHaveCount(1)
            ->and($page->jobs->pluck('id')->all())->toBe(['completed-75'])
            ->and($fixture['repositoryHydrations'])->toHaveCount(2)
            ->and($fixture['repositoryHydrations'][1])->toBe(['completed-75'])
            ->and($redis->metadataReads)->toBe([])
            ->and(array_diff($redis->expiredKeys, $redis->deletedKeys))->toBe([]);
    })->with([
        'job class after row 50' => [new JobIndexFiltersData(
            job: 'App\\Jobs\\ProductionOnly',
            queue: null,
            connection: null,
            state: null,
        )],
        'queue after row 50' => [new JobIndexFiltersData(
            job: null,
            queue: 'reports',
            connection: null,
            state: null,
        )],
        'connection after row 50' => [new JobIndexFiltersData(
            job: null,
            queue: null,
            connection: 'redis-secondary',
            state: null,
        )],
    ]);

    it('searches the complete retained source by partial job class or exact ID', function (
        string $query,
    ): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);

        $page = $fixture['query']->page(
            RetainedJobType::Completed,
            JobIndexFiltersData::none(),
            -1,
            search: $query,
        );

        expect($page->total)->toBe(1)
            ->and($page->jobs->pluck('id')->all())->toBe(['completed-75']);
    })->with([
        'partial class name beyond row 50' => ['production'],
        'exact retained ID beyond row 50' => ['completed-75'],
    ]);

    it('unions every matching job class before selecting the retained page', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $index = (int) str_replace('completed-', '', $id);
                $job = horizonJob($position, $id);
                $job->name = match (true) {
                    $index < 40 => 'App\\Jobs\\AlphaReport',
                    $index < 80 => 'App\\Jobs\\BetaReport',
                    default => 'App\\Jobs\\CleanupTask',
                };

                return $job;
            },
        );

        $page = $fixture['query']->page(
            RetainedJobType::Completed,
            JobIndexFiltersData::none(),
            -1,
            search: 'report',
        );

        expect($page->total)->toBe(80)
            ->and($page->jobs)->toHaveCount(50)
            ->and($page->jobs->pluck('id')->first())->toBe('completed-79')
            ->and($page->jobs->pluck('id')->last())->toBe('completed-30')
            ->and($redis->searchUnionWrites)->toBe(1);
    });

    it('queries high-cardinality filter catalogs in bounded pipelines', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(501, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->name = 'App\\Jobs\\CatalogTask'
                    .str_replace('completed-', '', $id);

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $redis->maxPipelineResults = 0;

        $catalog = $fixture['catalog']->for(RetainedJobType::Completed);

        expect($catalog->jobs)->toHaveCount(501)
            ->and($redis->maxPipelineResults)
            ->toBeLessThanOrEqual(500);
    });

    it('returns every distinct filter option without a materialization ceiling', function (): void {
        // Former public default was 1000 options; 1001 distinct classes must all materialize.
        $optionCount = 1001;
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource($optionCount, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->name = 'App\\Jobs\\CatalogTask'
                    .str_replace('completed-', '', $id);

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $redis->maxPipelineResults = 0;

        $catalog = $fixture['catalog']->for(RetainedJobType::Completed);

        expect($catalog->available)->toBeTrue()
            ->and($catalog->jobs)->toHaveCount($optionCount)
            ->and($catalog->jobs[0]['value'])->toBe('App\\Jobs\\CatalogTask0')
            ->and($catalog->jobs[$optionCount - 1]['value'])->toBe('App\\Jobs\\CatalogTask1000')
            ->and($redis->maxPipelineResults)->toBeLessThanOrEqual(500);
    });

    it('keeps exact queries available while stale projection members await cleanup', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(2, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->name = $id === 'completed-0'
                    ? 'App\\Jobs\\ExpiredTask'
                    : 'App\\Jobs\\RetainedTask';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $projectionKey = $fixture['index']->projectionKey(
            RetainedJobType::Completed,
        );
        $redis->seedSortedSet('completed_jobs', [
            'completed-1' => -2,
        ]);

        $catalog = $fixture['catalog']->for(RetainedJobType::Completed);
        $expired = $fixture['query']->page(
            RetainedJobType::Completed,
            new JobIndexFiltersData(
                job: 'App\\Jobs\\ExpiredTask',
                queue: null,
                connection: null,
                state: null,
            ),
            -1,
        );
        $retained = $fixture['query']->page(
            RetainedJobType::Completed,
            new JobIndexFiltersData(
                job: 'App\\Jobs\\RetainedTask',
                queue: null,
                connection: null,
                state: null,
            ),
            -1,
        );

        expect($catalog->available)->toBeTrue()
            ->and($catalog->jobs)->toBe([[
                'value' => 'App\\Jobs\\RetainedTask',
                'label' => 'RetainedTask',
            ]])
            ->and($expired->total)->toBe(0)
            ->and($expired->jobs)->toBeEmpty()
            ->and($retained->total)->toBe(1)
            ->and($retained->jobs->pluck('id')->all())
            ->toBe(['completed-1'])
            ->and($redis->sortedSets[$projectionKey] ?? [])
            ->toHaveKeys(['completed-0', 'completed-1']);
    });

    it('incrementally closes a finite source growth gap without rebuilding valid work', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $snapshotStores = 0;
        $redis->afterSourceSnapshotStore = static function (
            RetainedJobQueryRedisClient $client,
            string $_destination,
            array $_keys,
        ) use (&$snapshotStores): void {
            $snapshotStores++;

            if ($snapshotStores !== 1) {
                return;
            }

            $client->zadd('completed_jobs', -2, 'completed-1');
        };

        $page = $fixture['query']->page(
            RetainedJobType::Completed,
            new JobIndexFiltersData(
                job: 'App\\Jobs\\ImportFeed',
                queue: null,
                connection: null,
                state: null,
            ),
            -1,
        );
        $projectionKey = $fixture['index']->projectionKey(
            RetainedJobType::Completed,
        );

        expect($page->total)->toBe(2)
            ->and($page->jobs->pluck('id')->all())
            ->toEqualCanonicalizing(['completed-0', 'completed-1'])
            ->and($redis->sortedSets[$projectionKey] ?? [])
            ->toHaveKeys(['completed-0', 'completed-1'])
            ->and($fixture['repositoryHydrations'][0] ?? [])
            ->toBe(['completed-0'])
            ->and($fixture['repositoryHydrations'][1] ?? [])
            ->toBe(['completed-1']);
    });

    it('processes one final bounded growth slice without chasing a moving source', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $redis->afterSourceSnapshotStore = static function (
            RetainedJobQueryRedisClient $client,
            string $_destination,
            array $_keys,
        ): void {
            $client->zadd('completed_jobs', -2, 'completed-1');
        };
        $redis->afterFinalSourceDeltaStore = static function (
            RetainedJobQueryRedisClient $client,
            string $_destination,
            array $_keys,
        ): void {
            $client->zadd('completed_jobs', -3, 'completed-2');
        };

        $page = $fixture['query']->page(
            RetainedJobType::Completed,
            new JobIndexFiltersData(
                job: 'App\\Jobs\\ImportFeed',
                queue: null,
                connection: null,
                state: null,
            ),
            -1,
        );
        $projectionKey = $fixture['index']->projectionKey(
            RetainedJobType::Completed,
        );

        expect($page->total)->toBe(2)
            ->and($page->jobs->pluck('id')->all())
            ->toEqualCanonicalizing(['completed-0', 'completed-1'])
            ->and($redis->sortedSets['completed_jobs'] ?? [])
            ->toHaveKeys(['completed-0', 'completed-1', 'completed-2'])
            ->and($redis->sortedSets[$projectionKey] ?? [])
            ->toHaveKeys(['completed-0', 'completed-1']);

        expect($redis->sortedSets[$projectionKey] ?? [])
            ->not->toHaveKey('completed-2');
    });

    it('serves a queue facet from the last synchronized index without inspecting new source records', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $hydrations = &$fixture['repositoryHydrations'];
        $hydrations = [];
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
            'completed-1' => -2,
        ]);
        $redis->afterSourceSnapshotStore = static function (): never {
            throw new RuntimeException('The serving read inspected the retained source.');
        };

        $page = $fixture['query']->pageFromPublishedIndex(
            RetainedJobType::Completed,
            new JobIndexFiltersData(
                job: null,
                queue: 'default',
                connection: null,
                state: null,
            ),
            -1,
        );

        expect($page->total)->toBe(1)
            ->and($page->jobs->pluck('id')->all())->toBe(['completed-0'])
            ->and($hydrations)->toBe([['completed-0']]);
    });

    it('reports a cold published index without mutating it', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $redis->afterSourceSnapshotStore = static function (): never {
            throw new RuntimeException('The serving read inspected the retained source.');
        };

        expect(fn () => $fixture['query']->pageFromPublishedIndex(
            RetainedJobType::Completed,
            new JobIndexFiltersData(
                job: null,
                queue: 'default',
                connection: null,
                state: null,
            ),
            -1,
        ))->toThrow(RetainedJobIndexWarming::class);
    });

    it('serves the last published generation while a replacement synchronizes', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $pageDuringSynchronization = null;
        $redis->zadd('completed_jobs', -2, 'completed-1');
        $redis->afterSourceSnapshotStore = static function () use (
            $fixture,
            &$pageDuringSynchronization,
        ): void {
            $pageDuringSynchronization = $fixture['query']->pageFromPublishedIndex(
                RetainedJobType::Completed,
                new JobIndexFiltersData(
                    job: null,
                    queue: 'default',
                    connection: null,
                    state: null,
                ),
                -1,
            );
        };

        $fixture['index']->synchronize(RetainedJobType::Completed);

        if ($pageDuringSynchronization === null) {
            throw new RuntimeException(
                'The published page was not read during synchronization.',
            );
        }

        expect($pageDuringSynchronization->total)->toBe(1)
            ->and($pageDuringSynchronization->jobs->pluck('id')->all())
            ->toBe(['completed-0']);
    });

    it('publishes an empty synchronized index for serving reads', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $fixture = retainedJobQueryFixture($redis);

        $fixture['index']->synchronize(RetainedJobType::Completed);
        $redis->afterSourceSnapshotStore = static function (): never {
            throw new RuntimeException('The serving read inspected the retained source.');
        };

        $page = $fixture['query']->pageFromPublishedIndex(
            RetainedJobType::Completed,
            new JobIndexFiltersData(
                job: null,
                queue: 'default',
                connection: null,
                state: null,
            ),
            -1,
        );

        expect($page->total)->toBe(0)
            ->and($page->jobs)->toBeEmpty()
            ->and($fixture['repositoryHydrations'])->toBeEmpty();
    });

    it('reads published queue metadata and revision without hydrating jobs', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $hydrations = &$fixture['repositoryHydrations'];
        $hydrations = [];
        $filters = new JobIndexFiltersData(
            job: null,
            queue: 'reports',
            connection: null,
            state: null,
        );

        $revision = $fixture['query']->publishedRevision(
            RetainedJobType::Completed,
        );
        $metadata = $fixture['query']->publishedPageMetadata(
            RetainedJobType::Completed,
            $filters,
        );

        expect($revision)->toBeString()
            ->and($revision)->not->toBe('')
            ->and($metadata)->toBe([
                'total' => 1,
                'headId' => 'completed-75',
            ])
            ->and($hydrations)->toBe([]);
    });

    it('unions every matching job class without a search class ceiling', function (): void {
        // Former public default was 1000 matching classes; internal union chunks are 500 keys.
        // 1001 matching classes plus one non-match prove both ceilings are gone and unions chunk.
        $matchingClassCount = 1001;
        $totalJobs = $matchingClassCount + 1;
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource($totalJobs, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position) use ($matchingClassCount): object {
                $index = (int) str_replace('completed-', '', $id);
                $job = horizonJob($position, $id);
                $job->name = $index < $matchingClassCount
                    ? 'App\\Jobs\\ReportTask'.$index
                    : 'App\\Jobs\\CleanupTask';

                return $job;
            },
        );
        $jobs = new JobsData(
            $fixture['repository'],
            retainedQuery: $fixture['query'],
        );

        $page = $jobs->page(
            JobListType::Completed,
            -1,
            search: 'Report',
        );

        expect($page->available)->toBeTrue()
            ->and($page->total)->toBe($matchingClassCount)
            ->and(collect($page->items)->pluck('id')->all())->toHaveCount(50)
            ->and(collect($page->items)->pluck('id')->first())->toBe('completed-1000')
            ->and(collect($page->items)->pluck('id')->last())->toBe('completed-951')
            // 1001 keys → chunks of 500 + 500 + 1, then one reduction over 3 intermediates.
            ->and($redis->searchUnionWrites)->toBe(3);
    });

    it('does not materialize a class union when every retained job class matches', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(3, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->name = match ($id) {
                    'completed-0' => 'App\\Jobs\\AlphaTask',
                    'completed-1' => 'App\\Jobs\\BetaTask',
                    default => 'App\\Jobs\\GammaTask',
                };

                return $job;
            },
        );

        $page = $fixture['query']->page(
            RetainedJobType::Completed,
            JobIndexFiltersData::none(),
            -1,
            search: 'Task',
        );

        expect($page->total)->toBe(3)
            ->and($page->jobs->pluck('id')->all())->toBe([
                'completed-2',
                'completed-1',
                'completed-0',
            ])
            ->and($redis->searchUnionWrites)->toBe(0);
    });

    it('repairs a missing matching class facet before applying a partial search', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(2, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->name = $id === 'completed-0'
                    ? 'App\\Jobs\\AlphaTask'
                    : 'App\\Jobs\\BetaTask';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        unset($redis->sortedSets[$fixture['index']->facetKey(
            RetainedJobType::Completed,
            'job',
            'App\\Jobs\\AlphaTask',
        )]);

        $beta = $fixture['query']->page(
            RetainedJobType::Completed,
            JobIndexFiltersData::none(),
            -1,
            search: 'BetaTask',
        );
        $alpha = $fixture['query']->page(
            RetainedJobType::Completed,
            JobIndexFiltersData::none(),
            -1,
            search: 'AlphaTask',
        );

        expect($beta->total)->toBe(1)
            ->and($beta->jobs->pluck('id')->all())->toBe(['completed-1'])
            ->and($alpha->total)->toBe(1)
            ->and($alpha->jobs->pluck('id')->all())->toBe(['completed-0']);
    });

    it('repairs a missing class catalog membership before applying a partial search', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(2, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->name = $id === 'completed-0'
                    ? 'App\\Jobs\\AlphaTask'
                    : 'App\\Jobs\\BetaTask';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $redis->srem(
            $fixture['index']->catalogKey(
                RetainedJobType::Completed,
                'job',
            ),
            'App\\Jobs\\AlphaTask',
        );

        $beta = $fixture['query']->page(
            RetainedJobType::Completed,
            JobIndexFiltersData::none(),
            -1,
            search: 'BetaTask',
        );
        $alpha = $fixture['query']->page(
            RetainedJobType::Completed,
            JobIndexFiltersData::none(),
            -1,
            search: 'AlphaTask',
        );

        expect($beta->total)->toBe(1)
            ->and($beta->jobs->pluck('id')->all())->toBe(['completed-1'])
            ->and($alpha->total)->toBe(1)
            ->and($alpha->jobs->pluck('id')->all())->toBe(['completed-0']);
    });

    it('repairs missing single-valued catalog membership before returning filter values', function (
        string $dimension,
        string $firstValue,
        string $secondValue,
    ): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(2, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $first = $id === 'completed-0';
                $job->name = $first
                    ? 'App\\Jobs\\AlphaTask'
                    : 'App\\Jobs\\BetaTask';
                $job->queue = $first ? 'alpha' : 'beta';
                $job->connection = $first ? 'redis-alpha' : 'redis-beta';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $redis->srem(
            $fixture['index']->catalogKey(
                RetainedJobType::Completed,
                $dimension,
            ),
            $firstValue,
        );

        $values = $fixture['index']->catalogValues(
            RetainedJobType::Completed,
            $dimension,
        );

        expect($values)->toContain($firstValue, $secondValue);
    })->with([
        'job class' => [
            'job',
            'App\\Jobs\\AlphaTask',
            'App\\Jobs\\BetaTask',
        ],
        'queue' => ['queue', 'alpha', 'beta'],
        'connection' => ['connection', 'redis-alpha', 'redis-beta'],
    ]);

    it('repairs balanced single-valued facet corruption before returning filter values', function (
        string $dimension,
        string $firstValue,
        string $secondValue,
    ): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(3, 'completed'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $first = $id !== 'completed-2';
                $job->name = $first
                    ? 'App\\Jobs\\AlphaTask'
                    : 'App\\Jobs\\BetaTask';
                $job->queue = $first ? 'alpha' : 'beta';
                $job->connection = $first ? 'redis-alpha' : 'redis-beta';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $firstFacet = $fixture['index']->facetKey(
            RetainedJobType::Completed,
            $dimension,
            $firstValue,
        );
        $secondFacet = $fixture['index']->facetKey(
            RetainedJobType::Completed,
            $dimension,
            $secondValue,
        );
        $redis->sortedSets[$firstFacet] = [
            'completed-0' => -1,
            'completed-2' => -3,
        ];
        $redis->sortedSets[$secondFacet] = ['completed-2' => -3];
        $redis->expiredKeys = [];
        $redis->deletedKeys = [];

        $values = $fixture['index']->catalogValues(
            RetainedJobType::Completed,
            $dimension,
        );
        $firstFacet = $fixture['index']->facetKey(
            RetainedJobType::Completed,
            $dimension,
            $firstValue,
        );
        $secondFacet = $fixture['index']->facetKey(
            RetainedJobType::Completed,
            $dimension,
            $secondValue,
        );

        expect($values)->toContain($firstValue, $secondValue)
            ->and(array_keys($redis->sortedSets[$firstFacet]))
            ->toEqualCanonicalizing(['completed-0', 'completed-1'])
            ->and(array_keys($redis->sortedSets[$secondFacet]))
            ->toBe(['completed-2'])
            ->and(array_diff($redis->expiredKeys, $redis->deletedKeys))
            ->toBe([]);
    })->with([
        'job class' => [
            'job',
            'App\\Jobs\\AlphaTask',
            'App\\Jobs\\BetaTask',
        ],
        'queue' => ['queue', 'alpha', 'beta'],
        'connection' => ['connection', 'redis-alpha', 'redis-beta'],
    ]);

    it('repairs missing pending target membership before applying a state filter', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('pending_jobs', retainedSource(2, 'pending'));
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = $id === 'pending-0' ? 'alpha' : 'beta';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $firstTarget = json_encode(
            ['redis', 'alpha'],
            JSON_THROW_ON_ERROR,
        );
        $secondTarget = json_encode(
            ['redis', 'beta'],
            JSON_THROW_ON_ERROR,
        );
        $redis->srem(
            $fixture['index']->catalogKey(
                RetainedJobType::Pending,
                'target',
            ),
            $firstTarget,
        );

        $targets = $fixture['index']->catalogValues(
            RetainedJobType::Pending,
            'target',
        );

        expect($targets)->toContain($firstTarget, $secondTarget);
    });

    it('intersects an exact failed tag with the complete retained source', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('failed_jobs', retainedSource(101, 'failed'));
        $redis->seedSortedSet('failed:tenant:production', ['failed-75' => -76]);
        $fixture = retainedJobQueryFixture($redis);

        $page = $fixture['query']->page(
            RetainedJobType::Failed,
            JobIndexFiltersData::none(),
            -1,
            failedTag: 'tenant:production',
        );

        expect($page->total)->toBe(1)
            ->and($page->jobs->pluck('id')->all())->toBe(['failed-75']);
    });

    it('uses newest-first retained chronology and stable opaque cursors', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);
        $filters = new JobIndexFiltersData(null, 'default', null, null);

        $first = $fixture['query']->page(RetainedJobType::Completed, $filters, -1);
        $second = $fixture['query']->page(
            RetainedJobType::Completed,
            $filters,
            $first->next ?? -1,
        );

        expect($first->total)->toBe(100)
            ->and($first->jobs)->toHaveCount(50)
            ->and($first->jobs->pluck('id')->first())->toBe('completed-100')
            ->and($first->jobs->pluck('id')->last())->toBe('completed-50')
            ->and($first->next)->toBeString()
            ->and($first->next)->not->toBe('49')
            ->and($second->jobs->pluck('id')->first())->toBe('completed-49')
            ->and($second->jobs->pluck('id')->last())->toBe('completed-0')
            ->and($second->next)->toBeNull()
            ->and($redis->metadataReads)->toBe([]);
    });

    it('reads an unfiltered pending page from the retained source without scanning queue payloads', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('pending_jobs', retainedSource(101, 'pending'));
        $fixture = retainedJobQueryFixture($redis);
        $redis->intersectionWrites = 0;
        dashboardNeverReceives($fixture['repository'], 'getPending');
        dashboardNeverReceives($fixture['repository'], 'countPending');

        $page = (new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
        ))->page(JobListType::Pending, -1);
        $hydratedIds = $fixture['repositoryHydrations'][0] ?? [];

        expect($page->available)->toBeTrue()
            ->and($page->total)->toBe(101)
            ->and(array_column($page->items, 'id'))->toBe(array_map(
                static fn (int $index): string => "pending-{$index}",
                range(0, 49),
            ))
            ->and($page->next)->toBeString()
            ->and($fixture['repositoryHydrations'])->toHaveCount(1)
            ->and($hydratedIds)->toHaveCount(50)
            ->and($redis->intersectionWrites)->toBe(0);
    });

    it('does not skip unseen jobs when earlier source rows disappear', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);
        $filters = new JobIndexFiltersData(null, 'default', null, null);
        $first = $fixture['query']->page(
            RetainedJobType::Completed,
            $filters,
            -1,
        );

        foreach (range(91, 100) as $id) {
            $redis->zrem('completed_jobs', "completed-{$id}");
        }

        $second = $fixture['query']->page(
            RetainedJobType::Completed,
            $filters,
            $first->next,
        );

        expect($second->total)->toBe(90)
            ->and($second->jobs->pluck('id')->first())->toBe('completed-49')
            ->and($second->jobs->pluck('id')->last())->toBe('completed-0')
            ->and($second->jobs)->toHaveCount(50)
            ->and(array_diff($redis->expiredKeys, $redis->deletedKeys))->toBe([]);
    });

    it('keeps terminal equal-score pagination stable when its cursor disappears', function (
        RetainedJobType $type,
        string $sourceKey,
        string $prefix,
        bool $ascending,
    ): void {
        $redis = new RetainedJobQueryRedisClient;
        $source = array_fill_keys(
            array_map(
                static fn (int $index): string => "{$prefix}-{$index}",
                range(0, 60),
            ),
            -1.0,
        );
        $redis->seedSortedSet($sourceKey, $source);
        $fixture = retainedJobQueryFixture($redis);

        $first = $fixture['query']->page(
            $type,
            JobIndexFiltersData::none(),
            -1,
        );
        $firstIds = $first->jobs->pluck('id')->all();
        $lastKey = array_key_last($firstIds);

        if ($lastKey === null) {
            throw new LogicException('Expected retained jobs on the first page.');
        }

        $cursorId = $firstIds[$lastKey];
        $redis->zrem($sourceKey, $cursorId);

        $second = $fixture['query']->page(
            $type,
            JobIndexFiltersData::none(),
            $first->next,
        );
        $secondIds = $second->jobs->pluck('id')->all();
        $expected = array_keys($source);
        $ascending
            ? sort($expected, SORT_STRING)
            : rsort($expected, SORT_STRING);

        expect($firstIds)->toBe(array_slice($expected, 0, 50))
            ->and($second->total)->toBe(60)
            ->and($secondIds)->toBe(array_slice($expected, 50))
            ->and([...$firstIds, ...$secondIds])->toBe($expected);
    })->with([
        'completed jobs' => [
            RetainedJobType::Completed,
            'completed_jobs',
            'completed',
            true,
        ],
        'failed jobs' => [
            RetainedJobType::Failed,
            'failed_jobs',
            'failed',
            false,
        ],
    ]);

    it('keeps pending equal-score pagination stable when its cursor disappears', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $source = array_fill_keys(
            array_map(
                static fn (int $index): string => "pending-{$index}",
                range(0, 60),
            ),
            -1.0,
        );
        $redis->seedSortedSet('pending_jobs', $source);
        $fixture = retainedJobQueryFixture($redis);
        $filters = new JobIndexFiltersData(null, 'default', null, null);

        $first = $fixture['query']->page(
            RetainedJobType::Pending,
            $filters,
            -1,
        );
        $firstIds = $first->jobs->pluck('id')->all();
        $lastKey = array_key_last($firstIds);

        if ($lastKey === null) {
            throw new LogicException('Expected retained jobs on the first page.');
        }

        $cursorId = $firstIds[$lastKey];
        $redis->zrem('pending_jobs', $cursorId);

        $second = $fixture['query']->page(
            RetainedJobType::Pending,
            $filters,
            $first->next,
        );
        $secondIds = $second->jobs->pluck('id')->all();
        $expected = array_keys($source);
        rsort($expected, SORT_STRING);

        expect($firstIds)->toHaveCount(50)
            ->and($second->total)->toBe(60)
            ->and($secondIds)->toBe(array_slice($expected, 50))
            ->and(array_intersect($firstIds, $secondIds))->toBe([]);
    });

    it('uses native reserved membership for pending state beyond row 50', function (): void {
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(101, 'pending'));

        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->seedSortedSet('queues:reports:reserved', [
            json_encode(['uuid' => 'pending-75'], JSON_THROW_ON_ERROR) => 100,
        ]);
        $queueRedisFactory = mockDashboardContract(RedisFactory::class);
        dashboardReturns(
            $queueRedisFactory,
            'connection',
            new PredisConnection($queueRedis),
        );
        $queue = new RedisQueue($queueRedisFactory, 'default', 'default');
        $queues = mockDashboardContract(QueueFactory::class);
        dashboardReturnsFor($queues, 'connection', ['redis-secondary'], $queue);
        $pendingStates = new PendingJobStateIndex($queues);
        $fixture = retainedJobQueryFixture($horizonRedis, $pendingStates);

        $page = $fixture['query']->page(
            RetainedJobType::Pending,
            new JobIndexFiltersData(
                job: null,
                queue: 'reports',
                connection: null,
                state: 'reserved',
            ),
            -1,
        );

        expect($page->total)->toBe(1)
            ->and($page->jobs->pluck('id')->all())->toBe(['pending-75']);
    });

    it('surfaces live reserved jobs at the start of unfiltered pending pages without duplicates', function (): void {
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(60, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        // Live reserved jobs sit near the end of retained order, so a plain first page misses them.
        $queueRedis->seedSortedSet('queues:default:reserved', [
            json_encode(['uuid' => 'pending-55'], JSON_THROW_ON_ERROR) => 200,
            json_encode(['uuid' => 'pending-58'], JSON_THROW_ON_ERROR) => 100,
        ]);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position) use ($queueRedis): object {
                $job = horizonJob($position, $id);
                $job->status = isset($queueRedis->sortedSets['queues:default:reserved'][json_encode(
                    ['uuid' => $id],
                    JSON_THROW_ON_ERROR,
                )]) ? 'reserved' : 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $filters = JobIndexFiltersData::none();

        $first = $fixture['query']->page(RetainedJobType::Pending, $filters, -1);
        $firstIds = $first->jobs->pluck('id')->all();
        $second = $fixture['query']->page(
            RetainedJobType::Pending,
            $filters,
            $first->next ?? -1,
        );
        $secondIds = $second->jobs->pluck('id')->all();

        expect($first->total)->toBe(60)
            ->and(array_slice($firstIds, 0, 2))->toBe(['pending-55', 'pending-58'])
            ->and($firstIds)->toHaveCount(50)
            ->and($firstIds)->not->toContain('pending-56') // still later in retained order fill
            ->and(array_intersect($firstIds, $secondIds))->toBe([])
            ->and($secondIds)->not->toContain('pending-55')
            ->and($secondIds)->not->toContain('pending-58')
            ->and([...$firstIds, ...$secondIds])->toHaveCount(60);
    });

    it('continues reserved lead pages when more than one page of jobs is reserved', function (): void {
        $horizonRedis = new RetainedJobQueryRedisClient;
        $source = retainedSource(80, 'pending');
        $horizonRedis->seedSortedSet('pending_jobs', $source);
        $queueRedis = new RetainedJobQueryRedisClient;
        $reservedMembers = [];

        foreach (range(20, 74) as $index) {
            $reservedMembers[json_encode(['uuid' => "pending-{$index}"], JSON_THROW_ON_ERROR)] = $index;
        }

        $queueRedis->seedSortedSet('queues:default:reserved', $reservedMembers);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $filters = JobIndexFiltersData::none();

        $first = $fixture['query']->page(RetainedJobType::Pending, $filters, -1);
        $second = $fixture['query']->page(
            RetainedJobType::Pending,
            $filters,
            $first->next ?? -1,
        );
        $firstIds = $first->jobs->pluck('id')->all();
        $secondIds = $second->jobs->pluck('id')->all();
        $reservedIds = array_map(
            static fn (int $index): string => "pending-{$index}",
            range(20, 74),
        );

        expect($firstIds)->toHaveCount(50)
            ->and(array_diff($firstIds, $reservedIds))->toBe([])
            ->and(array_slice($secondIds, 0, 5))->toEqualCanonicalizing(array_values(array_diff(
                $reservedIds,
                $firstIds,
            )))
            ->and(array_intersect($firstIds, $secondIds))->toBe([])
            ->and($first->total)->toBe(80);
    });

    it('surfaces reserved-first pending pages from the published index path used by queue detail', function (): void {
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(60, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->seedSortedSet('queues:batches:reserved', [
            json_encode(['uuid' => 'pending-55'], JSON_THROW_ON_ERROR) => 100,
            json_encode(['uuid' => 'pending-58'], JSON_THROW_ON_ERROR) => 50,
        ]);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'batches';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $filters = new JobIndexFiltersData(
            job: null,
            queue: 'batches',
            connection: null,
            state: null,
        );

        $page = $fixture['query']->pageFromPublishedIndex(
            RetainedJobType::Pending,
            $filters,
            -1,
        );

        expect($page->total)->toBe(60)
            ->and(array_slice($page->jobs->pluck('id')->all(), 0, 2))
            ->toBe(['pending-55', 'pending-58']);
    });

    it('orders actively delayed pending jobs by delayed availability among the non-reserved rest', function (): void {
        Date::setTestNow(Date::createFromTimestamp(0));
        $horizonRedis = new RetainedJobQueryRedisClient;
        // pending-0..59 scores -1..-60 (reverse: pending-0 first).
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(60, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        // Early-enqueued delayed job becomes available at score -60 (past the first page).
        $queueRedis->seedSortedSet('queues:default:delayed', [
            json_encode(['uuid' => 'pending-5'], JSON_THROW_ON_ERROR) => 60.0,
        ]);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $filters = JobIndexFiltersData::none();

        $first = $fixture['query']->page(RetainedJobType::Pending, $filters, -1);
        $firstIds = $first->jobs->pluck('id')->all();

        expect($first->total)->toBe(60)
            ->and($firstIds)->toHaveCount(50)
            ->and($firstIds)->not->toContain('pending-5')
            ->and($firstIds[0])->toBe('pending-0')
            ->and($firstIds[4])->toBe('pending-4')
            ->and($firstIds[5])->toBe('pending-6');

        $second = $fixture['query']->page(
            RetainedJobType::Pending,
            $filters,
            $first->next ?? -1,
        );
        $secondIds = $second->jobs->pluck('id')->all();
        $combined = [...$firstIds, ...$secondIds];

        expect($combined)->toHaveCount(60)
            ->and($combined[59])->toBe('pending-5')
            ->and(array_values(array_unique($combined)))->toHaveCount(60);
    });

    it('keeps reserved jobs ahead of delayed availability ordering on the rest stream', function (): void {
        Date::setTestNow(Date::createFromTimestamp(0));
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(20, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->seedSortedSet('queues:default:reserved', [
            json_encode(['uuid' => 'pending-18'], JSON_THROW_ON_ERROR) => 100,
        ]);
        $queueRedis->seedSortedSet('queues:default:delayed', [
            json_encode(['uuid' => 'pending-2'], JSON_THROW_ON_ERROR) => 16.0,
        ]);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);

        $page = $fixture['query']->page(
            RetainedJobType::Pending,
            JobIndexFiltersData::none(),
            -1,
        );
        $ids = $page->jobs->pluck('id')->all();

        $pendingTwo = array_search('pending-2', $ids, true);
        $pendingFourteen = array_search('pending-14', $ids, true);

        expect($pendingTwo)->toBeInt()
            ->and($pendingFourteen)->toBeInt();

        if (! is_int($pendingTwo) || ! is_int($pendingFourteen)) {
            throw new RuntimeException('Expected pending job positions in the page.');
        }

        expect($ids[0])->toBe('pending-18')
            ->and($ids)->toContain('pending-2')
            ->and($pendingTwo)->toBeGreaterThan($pendingFourteen);
    });

    it('orders delayed jobs by availability across filtered and published pending pages', function (): void {
        Date::setTestNow(Date::createFromTimestamp(0));
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(30, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->seedSortedSet('queues:reports:delayed', [
            json_encode(['uuid' => 'pending-3'], JSON_THROW_ON_ERROR) => 20.0,
        ]);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'reports';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $filters = new JobIndexFiltersData(
            job: null,
            queue: 'reports',
            connection: null,
            state: null,
        );

        $live = $fixture['query']->page(RetainedJobType::Pending, $filters, -1);
        $published = $fixture['query']->pageFromPublishedIndex(
            RetainedJobType::Pending,
            $filters,
            -1,
        );
        $liveIds = $live->jobs->pluck('id')->all();
        $publishedIds = $published->jobs->pluck('id')->all();

        // pending-3 moves from index 3 to after scores better than -20 (pending-0..18 except self).
        expect($liveIds[18])->toBe('pending-3')
            ->and($publishedIds)->toBe($liveIds);
    });

    it('reverts a released delayed job to enqueue-time order on the next page', function (): void {
        Date::setTestNow(Date::createFromTimestamp(0));
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(20, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        $delayedPayload = json_encode(['uuid' => 'pending-2'], JSON_THROW_ON_ERROR);
        $queueRedis->seedSortedSet('queues:default:delayed', [
            $delayedPayload => 15.0,
        ]);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $filters = JobIndexFiltersData::none();

        $whileDelayed = $fixture['query']->page(
            RetainedJobType::Pending,
            $filters,
            -1,
        )->jobs->pluck('id')->all();

        unset($queueRedis->sortedSets['queues:default:delayed'][$delayedPayload]);

        $afterRelease = $fixture['query']->page(
            RetainedJobType::Pending,
            $filters,
            -1,
        )->jobs->pluck('id')->all();

        expect($whileDelayed[13])->toBe('pending-2')
            ->and($afterRelease[2])->toBe('pending-2');
    });

    it('does not materialize a full candidate set when ordering delayed jobs on the unfiltered path', function (): void {
        Date::setTestNow(Date::createFromTimestamp(0));
        $horizonRedis = new RetainedJobQueryRedisClient;
        // Publish a tiny warm generation first so pending target discovery can use the
        // published catalog without rebuilding a 100k projection in this test process.
        // Serving the unfiltered page must still page the large live source directly.
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(1, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->seedSortedSet('queues:default:delayed', [
            json_encode(['uuid' => 'pending-10'], JSON_THROW_ON_ERROR) => 1_000.0,
            json_encode(['uuid' => 'pending-90000'], JSON_THROW_ON_ERROR) => 90_000.0,
        ]);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        // Grow the retained source after publish. Large enough that copying it into a
        // temporary candidate set would exhaust the package suite's 128 MB budget.
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(100_000, 'pending'));
        $horizonRedis->intersectionWrites = 0;
        $horizonRedis->unionWrites = 0;
        $hydrationCountBeforePage = count($fixture['repositoryHydrations']);

        $page = $fixture['query']->page(
            RetainedJobType::Pending,
            JobIndexFiltersData::none(),
            -1,
        );
        $pageHydrations = array_slice(
            $fixture['repositoryHydrations'],
            $hydrationCountBeforePage,
        );
        $hydrated = $pageHydrations[0] ?? [];

        expect($page->total)->toBe(100_000)
            ->and($page->jobs)->toHaveCount(50)
            ->and($pageHydrations)->toHaveCount(1)
            ->and($hydrated)->toHaveCount(50)
            ->and($horizonRedis->intersectionWrites)->toBe(0)
            ->and($horizonRedis->unionWrites)->toBe(0)
            ->and($page->jobs->pluck('id')->all())->not->toContain('pending-10')
            ->and($page->jobs->pluck('id')->all())->not->toContain('pending-90000');
    });

    it('serves unfiltered reserved-first pending from live source scores while the source keeps growing', function (): void {
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(20, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->seedSortedSet('queues:default:reserved', [
            json_encode(['uuid' => 'pending-15'], JSON_THROW_ON_ERROR) => 100,
        ]);
        $queueRedis->seedSortedSet('queues:default:delayed', [
            json_encode(['uuid' => 'pending-2'], JSON_THROW_ON_ERROR) => 16.0,
        ]);
        Date::setTestNow(Date::createFromTimestamp(0));
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        expect($fixture['index']->publishedRevision(RetainedJobType::Pending))
            ->toBeString();

        // Post-publish: new retained pending job that is natively reserved.
        // Score -0.5 leads source order (pending reverse: highest score first).
        // A stale published membership read would omit it from the reserved lead
        // while still excluding it from the rest via live reservedIds.
        $horizonRedis->zadd('pending_jobs', -0.5, 'pending-new-reserved');
        $queueRedis->zadd(
            'queues:default:reserved',
            200,
            json_encode(['uuid' => 'pending-new-reserved'], JSON_THROW_ON_ERROR),
        );

        foreach (range(1, 5) as $index) {
            $horizonRedis->zadd(
                'pending_jobs',
                -(1_000 + $index),
                "pending-churn-{$index}",
            );
        }

        $materializeAttempts = 0;
        $horizonRedis->afterSourceSnapshotStore = static function (
            RetainedJobQueryRedisClient $_client,
            string $_destination,
            array $keys,
        ) use (&$materializeAttempts): void {
            if (($keys[0] ?? null) !== 'pending_jobs') {
                return;
            }

            $materializeAttempts++;

            throw new RuntimeException(
                'The retained job index could not be synchronized.',
            );
        };

        // Filtered membership still requires a candidate key and must fail closed.
        expect(fn () => $fixture['index']->pageIds(
            RetainedJobType::Pending,
            ['queue' => 'default'],
            ['pending-15' => true],
            null,
            50,
        ))->toThrow(
            RuntimeException::class,
            'The retained job index could not be synchronized.',
        );

        $page = $fixture['query']->page(
            RetainedJobType::Pending,
            JobIndexFiltersData::none(),
            -1,
        );
        $ids = $page->jobs->pluck('id')->all();

        // Live source-score reserved lead (not stale published membership):
        // total includes post-publish members; reserved lead is source-ordered.
        expect($page->total)->toBe(26)
            ->and(array_slice($ids, 0, 2))->toBe([
                'pending-new-reserved',
                'pending-15',
            ])
            ->and($ids)->toContain('pending-2')
            ->and($ids)->toContain('pending-churn-1')
            ->and($materializeAttempts)->toBe(1);
    });

    it('orders an explicit delayed state page by delayed availability scores', function (): void {
        Date::setTestNow(Date::createFromTimestamp(0));
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(10, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        // Enqueue order is pending-0..9; availability order should reverse the delayed pair.
        $queueRedis->seedSortedSet('queues:default:delayed', [
            json_encode(['uuid' => 'pending-1'], JSON_THROW_ON_ERROR) => 30.0,
            json_encode(['uuid' => 'pending-8'], JSON_THROW_ON_ERROR) => 10.0,
            json_encode(['uuid' => 'pending-3'], JSON_THROW_ON_ERROR) => 20.0,
        ]);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $horizonRedis->intersectionWrites = 0;
        $horizonRedis->unionWrites = 0;

        $page = $fixture['query']->page(
            RetainedJobType::Pending,
            new JobIndexFiltersData(
                job: null,
                queue: null,
                connection: null,
                state: 'delayed',
            ),
            -1,
        );

        // Reverse on -availability: -10, -20, -30 => pending-8, pending-3, pending-1.
        expect($page->total)->toBe(3)
            ->and($page->jobs->pluck('id')->all())->toBe([
                'pending-8',
                'pending-3',
                'pending-1',
            ])
            ->and($horizonRedis->intersectionWrites)->toBe(0)
            ->and($horizonRedis->unionWrites)->toBe(0);
    });

    it('excludes ghost delayed payloads that Horizon no longer retains', function (): void {
        Date::setTestNow(Date::createFromTimestamp(0));
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet('pending_jobs', retainedSource(5, 'pending'));
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->seedSortedSet('queues:default:delayed', [
            json_encode(['uuid' => 'pending-1'], JSON_THROW_ON_ERROR) => 20.0,
            // Still in the queue delayed zset, but not in Horizon pending_jobs.
            json_encode(['uuid' => 'ghost-delayed'], JSON_THROW_ON_ERROR) => 5.0,
            json_encode(['uuid' => 'pending-3'], JSON_THROW_ON_ERROR) => 10.0,
        ]);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $horizonRedis->intersectionWrites = 0;
        $horizonRedis->unionWrites = 0;

        $page = $fixture['query']->page(
            RetainedJobType::Pending,
            new JobIndexFiltersData(
                job: null,
                queue: null,
                connection: null,
                state: 'delayed',
            ),
            -1,
        );

        expect($page->total)->toBe(2)
            ->and($page->jobs->pluck('id')->all())->toBe([
                'pending-3',
                'pending-1',
            ])
            ->and($page->jobs->pluck('id')->all())->not->toContain('ghost-delayed')
            ->and($horizonRedis->intersectionWrites)->toBe(0)
            ->and($horizonRedis->unionWrites)->toBe(0);
    });

    it('bounds delayed retained-presence pipelines to SOURCE_CHUNK_SIZE', function (): void {
        Date::setTestNow(Date::createFromTimestamp(0));
        $delayedCount = 1_001;
        $horizonRedis = new RetainedJobQueryRedisClient;
        $horizonRedis->seedSortedSet(
            'pending_jobs',
            retainedSource($delayedCount, 'pending'),
        );
        $queueRedis = new RetainedJobQueryRedisClient;
        $delayedMembers = [];

        foreach (range(0, $delayedCount - 1) as $index) {
            $delayedMembers[json_encode(
                ['uuid' => "pending-{$index}"],
                JSON_THROW_ON_ERROR,
            )] = (float) ($index + 1);
        }

        $queueRedis->seedSortedSet('queues:default:delayed', $delayedMembers);
        $fixture = retainedJobQueryFixture(
            $horizonRedis,
            pendingJobStateIndexFor($queueRedis),
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->status = 'pending';
                $job->completed_at = null;
                $job->queue = 'default';
                $job->connection = 'redis';

                return $job;
            },
        );
        $fixture['index']->synchronize(RetainedJobType::Pending);
        $horizonRedis->maxPipelineResults = 0;
        $horizonRedis->intersectionWrites = 0;
        $horizonRedis->unionWrites = 0;

        $page = $fixture['query']->page(
            RetainedJobType::Pending,
            new JobIndexFiltersData(
                job: null,
                queue: null,
                connection: null,
                state: 'delayed',
            ),
            -1,
        );

        expect($page->total)->toBe($delayedCount)
            ->and($page->jobs)->toHaveCount(50)
            ->and($horizonRedis->maxPipelineResults)->toBeLessThanOrEqual(1_000)
            ->and($horizonRedis->maxPipelineResults)->toBeGreaterThan(0)
            ->and($horizonRedis->intersectionWrites)->toBe(0)
            ->and($horizonRedis->unionWrites)->toBe(0);
    });

    it('uses Laravel queue hash tags for pending state snapshots on clustered connections', function (): void {
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->lists['queues:{default}'] = [
            json_encode(['uuid' => 'ready-clustered'], JSON_THROW_ON_ERROR),
        ];
        $queueRedisFactory = mockDashboardContract(RedisFactory::class);
        dashboardReturns(
            $queueRedisFactory,
            'connection',
            new ClusteredRetainedJobQueryConnection($queueRedis),
        );
        $queue = new RedisQueue($queueRedisFactory, 'default', 'default');
        $queues = mockDashboardContract(QueueFactory::class);
        dashboardReturns($queues, 'connection', $queue);
        $pendingStates = new PendingJobStateIndex($queues);

        expect($pendingStates->matchingIds([[
            'connection' => 'redis',
            'queue' => 'default',
        ]], 'ready'))->toBe([
            'ready-clustered' => true,
        ]);
    });

    it('does not omit ready jobs when workers shift the live list during a chunked scan', function (): void {
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->lists['queues:default'] = array_map(
            static fn (int $index): string => json_encode(
                ['uuid' => "ready-{$index}"],
                JSON_THROW_ON_ERROR,
            ),
            range(0, 600),
        );
        $queueRedis->afterNextListRange = static function (
            RetainedJobQueryRedisClient $client,
        ): void {
            array_splice($client->lists['queues:default'], 0, 100);
        };
        $states = pendingJobStateIndexFor($queueRedis);

        $matches = $states->matchingIds([[
            'connection' => 'redis',
            'queue' => 'default',
        ]], 'ready');
        $remainingSnapshotKeys = array_values(array_filter(
            [
                ...array_keys($queueRedis->lists),
                ...array_keys($queueRedis->sortedSets),
                ...array_keys($queueRedis->strings),
            ],
            static fn (string $key): bool => str_contains(
                $key,
                ':horizon-new-dawn:pending-snapshot:',
            ),
        ));
        $renewedSnapshotKeys = array_values(array_filter(
            $queueRedis->expiredKeys,
            static fn (string $key): bool => str_contains(
                $key,
                ':horizon-new-dawn:pending-snapshot:',
            ),
        ));

        expect($matches)->toHaveCount(601)
            ->and($matches)->toHaveKey('ready-550')
            ->and($queueRedis->afterNextListRange)->toBeNull()
            ->and($remainingSnapshotKeys)->toBe([])
            ->and($renewedSnapshotKeys)->toHaveCount(6);
    });

    it('fails closed when a pending state snapshot expires between chunks', function (): void {
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->lists['queues:default'] = array_map(
            static fn (int $index): string => json_encode(
                ['uuid' => "ready-{$index}"],
                JSON_THROW_ON_ERROR,
            ),
            range(0, 500),
        );
        $queueRedis->afterNextListRange = static function (
            RetainedJobQueryRedisClient $client,
        ): void {
            foreach (array_keys($client->strings) as $key) {
                if (
                    str_contains(
                        $key,
                        ':horizon-new-dawn:pending-snapshot:',
                    )
                ) {
                    unset($client->strings[$key]);
                }
            }
        };
        $states = pendingJobStateIndexFor($queueRedis);

        expect(fn () => $states->matchingIds([[
            'connection' => 'redis',
            'queue' => 'default',
        ]], 'ready'))->toThrow(
            RuntimeException::class,
            'snapshot expired; refresh is required',
        )->and(array_values(array_filter(
            [
                ...array_keys($queueRedis->lists),
                ...array_keys($queueRedis->sortedSets),
                ...array_keys($queueRedis->strings),
            ],
            static fn (string $key): bool => str_contains(
                $key,
                ':horizon-new-dawn:pending-snapshot:',
            ),
        )))->toBe([]);
    });

    it('cleans partial pending snapshots when creation fails', function (): void {
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->lists['queues:default'] = [
            json_encode(['uuid' => 'ready'], JSON_THROW_ON_ERROR),
        ];
        $queueRedis->failNextPendingSnapshotCreation = true;
        $states = pendingJobStateIndexFor($queueRedis);

        expect(fn () => $states->matchingIds([[
            'connection' => 'redis',
            'queue' => 'default',
        ]], 'ready'))->toThrow(
            RuntimeException::class,
            'snapshot creation failed',
        )->and(array_values(array_filter(
            [
                ...array_keys($queueRedis->lists),
                ...array_keys($queueRedis->sortedSets),
                ...array_keys($queueRedis->strings),
            ],
            static fn (string $key): bool => str_contains(
                $key,
                ':horizon-new-dawn:pending-snapshot:',
            ),
        )))->toBe([]);
    });

    it('does not omit reserved jobs when workers shift the live sorted set during a chunked scan', function (): void {
        $queueRedis = new RetainedJobQueryRedisClient;
        $payloads = array_map(
            static fn (int $index): string => json_encode(
                ['uuid' => "reserved-{$index}"],
                JSON_THROW_ON_ERROR,
            ),
            range(0, 600),
        );
        $queueRedis->seedSortedSet(
            'queues:default:reserved',
            array_combine($payloads, range(0, 600)),
        );
        $queueRedis->afterNextSortedRange = static function (
            RetainedJobQueryRedisClient $client,
        ): void {
            asort($client->sortedSets['queues:default:reserved']);

            foreach (
                array_slice(
                    array_keys(
                        $client->sortedSets['queues:default:reserved'],
                    ),
                    0,
                    100,
                ) as $payload
            ) {
                unset(
                    $client->sortedSets[
                        'queues:default:reserved'
                    ][$payload],
                );
            }
        };
        $states = pendingJobStateIndexFor($queueRedis);

        $matches = $states->matchingIds([[
            'connection' => 'redis',
            'queue' => 'default',
        ]], 'reserved');

        expect($matches)->toHaveCount(601)
            ->and($matches)->toHaveKey('reserved-550')
            ->and($queueRedis->afterNextSortedRange)->toBeNull();
    });

    it('snapshots released list and delayed membership atomically', function (): void {
        Date::setTestNow('2026-07-27 12:00:00 UTC');
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->lists['queues:default'] = [];
        $duePayload = json_encode(
            ['uuid' => 'released-during-scan'],
            JSON_THROW_ON_ERROR,
        );
        $queueRedis->seedSortedSet(
            'queues:default:delayed',
            [$duePayload => Date::now()->getTimestamp() - 1],
        );
        $queueRedis->afterNextListRange = static function (
            RetainedJobQueryRedisClient $client,
        ) use ($duePayload): void {
            unset(
                $client->sortedSets[
                    'queues:default:delayed'
                ][$duePayload],
            );
            $client->lists['queues:default'][] = $duePayload;
        };
        $states = pendingJobStateIndexFor($queueRedis);

        try {
            $matches = $states->matchingIds([[
                'connection' => 'redis',
                'queue' => 'default',
            ]], 'released');

            expect($matches)->toBe([
                'released-during-scan' => true,
            ])->and($queueRedis->afterNextListRange)->toBeNull();
        } finally {
            Date::setTestNow();
        }
    });

    it('distinguishes every pending state from native queue structures', function (): void {
        Date::setTestNow('2026-07-26 12:00:00 UTC');
        $queueRedis = new RetainedJobQueryRedisClient;
        $queueRedis->lists['queues:default'] = [
            json_encode(['uuid' => 'ready'], JSON_THROW_ON_ERROR),
            json_encode([
                'uuid' => 'made-available',
                'horizonNewDawn' => ['madeAvailableAt' => Date::now()->getTimestamp() - 30],
                'createdAt' => Date::now()->getTimestamp() - 120,
                'delay' => 60,
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'uuid' => 'historically-released',
                'createdAt' => Date::now()->getTimestamp() - 120,
                'delay' => 60,
            ], JSON_THROW_ON_ERROR),
        ];
        $queueRedis->seedSortedSet('queues:default:delayed', [
            json_encode(['uuid' => 'delayed'], JSON_THROW_ON_ERROR) => Date::now()->getTimestamp() + 60,
            json_encode(['uuid' => 'due-but-not-migrated'], JSON_THROW_ON_ERROR) => Date::now()->getTimestamp() - 1,
        ]);
        $queueRedis->seedSortedSet('queues:default:reserved', [
            json_encode(['uuid' => 'reserved'], JSON_THROW_ON_ERROR) => Date::now()->getTimestamp() + 60,
        ]);
        $queueRedisFactory = mockDashboardContract(RedisFactory::class);
        dashboardReturns(
            $queueRedisFactory,
            'connection',
            new PredisConnection($queueRedis),
        );
        $queue = new RedisQueue($queueRedisFactory, 'default', 'default');
        $queues = mockDashboardContract(QueueFactory::class);
        dashboardReturns($queues, 'connection', $queue);
        $states = new PendingJobStateIndex($queues);
        $target = [['connection' => 'redis', 'queue' => 'default']];
        $asOf = (string) Date::now()->getTimestamp();

        $ready = $states->matchingIds($target, 'ready');

        expect($ready)->toHaveKeys(['ready', 'made-available'])
            ->and(array_key_exists('historically-released', $ready))->toBeFalse()
            ->and($states->matchingIds($target, 'released'))->toHaveKeys([
                'historically-released',
                'due-but-not-migrated',
            ])
            ->and($states->matchingIds($target, 'released'))->not->toHaveKey(
                'made-available',
            )
            ->and($states->matchingIds($target, 'delayed'))->toHaveKey('delayed')
            ->and($states->matchingIds($target, 'delayed'))->not->toHaveKey(
                'due-but-not-migrated',
            )
            ->and($states->matchingIds($target, 'reserved'))->toHaveKey('reserved')
            ->and($queueRedis->scoreRanges)->toContain(
                [
                    'key' => 'queues:default:delayed',
                    'min' => '-inf',
                    'max' => $asOf,
                ],
                [
                    'key' => 'queues:default:delayed',
                    'min' => "({$asOf}",
                    'max' => '+inf',
                ],
            );
    });

    it('builds filter options from every retained job without hydrating rows again', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);

        $catalog = $fixture['catalog']->for(RetainedJobType::Completed);

        expect($catalog->jobs)->toContain([
            'value' => 'App\\Jobs\\ProductionOnly',
            'label' => 'ProductionOnly',
        ])->and($catalog->queues)->toContain('reports')
            ->and($catalog->connections)->toContain('redis-secondary')
            ->and($fixture['repositoryHydrations'])->toHaveCount(1)
            ->and($redis->intersectionWrites)->toBeLessThanOrEqual(10)
            ->and(array_diff($redis->expiredKeys, $redis->deletedKeys))
            ->toBe([]);
    });

    it('uses an application-specific namespace that cannot collide accidentally with Horizon tags', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);
        $fixture['catalog']->for(RetainedJobType::Completed);

        $catalogKey = $fixture['index']->catalogKey(
            RetainedJobType::Completed,
            'queue',
        );

        expect($catalogKey)->toStartWith("\x1fhorizon-new-dawn:v2:")
            ->and($catalogKey)->not->toBe(
                'horizon_new_dawn:jobs:completed:catalog:queue',
            )
            ->and($redis->sets)->toHaveKey($catalogKey);
    });

    it('rebuilds facets when stale metadata is already missing', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);
        $fixture['catalog']->for(RetainedJobType::Completed);
        $reportsFacet = $fixture['index']->facetKey(
            RetainedJobType::Completed,
            'queue',
            'reports',
        );
        $queueCatalog = $fixture['index']->catalogKey(
            RetainedJobType::Completed,
            'queue',
        );
        $redis->hdel($fixture['index']->metadataKey(), 'completed-75');
        $redis->zrem('completed_jobs', 'completed-75');

        $fixture['index']->synchronize(RetainedJobType::Completed, force: true);
        $reportsFacet = $fixture['index']->facetKey(
            RetainedJobType::Completed,
            'queue',
            'reports',
        );
        $queueCatalog = $fixture['index']->catalogKey(
            RetainedJobType::Completed,
            'queue',
        );

        expect($redis->sets[$queueCatalog] ?? [])->not->toHaveKey('reports')
            ->and($redis->zscore($reportsFacet, 'completed-75'))->toBeFalse()
            ->and($fixture['repositoryHydrations'])->toHaveCount(1);

        $repaired = $fixture['catalog']->for(RetainedJobType::Completed);

        expect($repaired->queues)->not->toContain('reports')
            ->and($fixture['repositoryHydrations'])->toHaveCount(1);
    });

    it('keeps every filter catalog scoped to its retained type', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $redis->seedSortedSet('failed_jobs', retainedSource(10, 'failed'));
        $fixture = retainedJobQueryFixture($redis);

        $completed = $fixture['catalog']->for(RetainedJobType::Completed);
        $failed = $fixture['catalog']->for(RetainedJobType::Failed);

        expect($completed->queues)->toContain('reports')
            ->and($failed->queues)->not->toContain('reports');
    });

    it('normalizes an exact global result through JobsData and FailedJobsData', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $redis->seedSortedSet('failed_jobs', retainedSource(101, 'failed'));
        $redis->seedSortedSet('failed:tenant:production', ['failed-75' => -76]);
        $fixture = retainedJobQueryFixture($redis);
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $filters = new JobIndexFiltersData(null, 'reports', null, null);

        $completed = $jobs->page(JobListType::Completed, -1, $filters);
        $failed = (new FailedJobsData(
            repository: $fixture['repository'],
            tags: mockDashboardContract(TagRepository::class),
            jobs: $jobs,
            retryEligibility: new FailedJobRetryEligibility,
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        ))->page(-1, 'tenant:production', $filters);

        expect($completed->total)->toBe(1)
            ->and($completed->items[0]->id)->toBe('completed-75')
            ->and($failed->total)->toBe(1)
            ->and($failed->items[0]->id)->toBe('failed-75')
            ->and($jobs->filters(JobListType::Completed)->available)->toBeTrue();
    });

    it('uses the same complete queue predicate for queue activity', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $failed = new FailedJobsData(
            repository: $fixture['repository'],
            tags: mockDashboardContract(TagRepository::class),
            jobs: $jobs,
            retryEligibility: new FailedJobRetryEligibility,
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: $failed,
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);

        $page = $queues->page(
            'reports',
            QueueActivityTab::Completed,
            -1,
        );

        expect($page->available)->toBeTrue()
            ->and($page->complete)->toBeTrue()
            ->and($page->total)->toBe(1)
            ->and($page->rows[0]->id)->toBe('completed-75');
    });

    it('builds a cheap queue list revision without hydrating the active page', function (): void {
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', retainedSource(101, 'completed'));
        $fixture = retainedJobQueryFixture($redis);
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: new FailedJobsData(
                repository: $fixture['repository'],
                tags: mockDashboardContract(TagRepository::class),
                jobs: $jobs,
                retryEligibility: new FailedJobRetryEligibility,
                retainedQuery: $fixture['query'],
                filterCatalog: $fixture['catalog'],
            ),
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $hydrations = &$fixture['repositoryHydrations'];
        $hydrations = [];

        $revision = json_decode(
            $queues->listRevision('reports', QueueActivityTab::Completed),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($revision)->toBe([
            $fixture['query']->publishedRevision(RetainedJobType::Completed),
            1,
            'completed-75',
        ])->and($hydrations)->toBe([]);
    });

    it('invalidates a queue page cache from the published revision without scanning retained sources', function (): void {
        config()->set('horizon-new-dawn.poll_interval', 0);
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->queue = 'reports';
                $job->status = 'completed';

                return $job;
            },
        );
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: new FailedJobsData(
                repository: $fixture['repository'],
                tags: mockDashboardContract(TagRepository::class),
                jobs: $jobs,
                retryEligibility: new FailedJobRetryEligibility,
                retainedQuery: $fixture['query'],
                filterCatalog: $fixture['catalog'],
            ),
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $initial = $queues->page('reports', QueueActivityTab::Completed, -1);
        $redis->zadd('completed_jobs', -2, 'completed-1');
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $redis->afterSourceSnapshotStore = static function (): never {
            throw new RuntimeException('A query-local cache refresh scanned the retained source.');
        };

        $refreshed = $queues->page('reports', QueueActivityTab::Completed, -1);

        expect($initial->total)->toBe(1)
            ->and($refreshed->total)->toBe(2)
            ->and(array_column($refreshed->toArray()['rows'], 'id'))
            ->toBe(['completed-1', 'completed-0']);
    });

    it('serves the last cached queue page while its published pointer is temporarily missing', function (): void {
        config()->set('horizon-new-dawn.poll_interval', 0);
        config()->set('horizon.prefix', 'queue-last-good-page:');
        app(CacheFactory::class)->store()->clear();
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: new FailedJobsData(
                repository: $fixture['repository'],
                tags: mockDashboardContract(TagRepository::class),
                jobs: $jobs,
                retryEligibility: new FailedJobRetryEligibility,
                retainedQuery: $fixture['query'],
                filterCatalog: $fixture['catalog'],
            ),
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $initial = $queues->page('default', QueueActivityTab::Completed, -1);
        unset(
            $redis->strings[retainedPublishedGenerationKey(
                $redis,
                RetainedJobType::Completed,
            )],
        );

        $cached = $queues->page('default', QueueActivityTab::Completed, -1);

        expect($initial->total)->toBe(1)
            ->and($cached->total)->toBe(1)
            ->and($cached->warming)->toBeFalse()
            ->and($cached->rows[0]->id)->toBe('completed-0');
    });

    it('deduplicates retained reconciliation globally by Horizon prefix and job type', function (): void {
        config()->set('horizon-new-dawn.poll_interval', 0);
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-default' => -1,
            'completed-reports' => -2,
        ]);
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->queue = str_ends_with($id, 'reports')
                    ? 'reports'
                    : 'default';
                $job->status = 'completed';

                return $job;
            },
        );
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: new FailedJobsData(
                repository: $fixture['repository'],
                tags: mockDashboardContract(TagRepository::class),
                jobs: $jobs,
                retryEligibility: new FailedJobRetryEligibility,
                retainedQuery: $fixture['query'],
                filterCatalog: $fixture['catalog'],
            ),
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);
        $sourceSnapshots = 0;
        $redis->afterSourceSnapshotStore = static function () use (
            &$sourceSnapshots,
        ): void {
            $sourceSnapshots++;
        };

        $queues->page('default', QueueActivityTab::Completed, -1);
        $queues->page('reports', QueueActivityTab::Completed, -1);
        $queues->listRevision('reports', QueueActivityTab::Completed);
        app(DeferredCallbackCollection::class)->invoke();

        expect($sourceSnapshots)->toBe(1);
    });

    it('serves a stale queue page immediately and refreshes it after the response', function (): void {
        config()->set('horizon-new-dawn.poll_interval', 5_000);
        Date::setTestNow('2026-07-20 12:00:00 UTC');
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture(
            $redis,
            jobFactory: static function (string $id, int $position): object {
                $job = horizonJob($position, $id);
                $job->queue = 'reports';
                $job->status = 'completed';

                return $job;
            },
        );
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: new FailedJobsData(
                repository: $fixture['repository'],
                tags: mockDashboardContract(TagRepository::class),
                jobs: $jobs,
                retryEligibility: new FailedJobRetryEligibility,
                retainedQuery: $fixture['query'],
                filterCatalog: $fixture['catalog'],
            ),
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );
        $fixture['index']->synchronize(RetainedJobType::Completed);

        $initial = $queues->page('reports', QueueActivityTab::Completed, -1);
        app(DeferredCallbackCollection::class)->invoke();
        $redis->zadd('completed_jobs', -2, 'completed-1');
        Date::setTestNow('2026-07-20 12:00:06 UTC');

        $stale = $queues->page('reports', QueueActivityTab::Completed, -1);

        expect($initial->total)->toBe(1)
            ->and($stale->total)->toBe(1)
            ->and($stale->rows[0]->id)->toBe('completed-0');

        app(DeferredCallbackCollection::class)->invoke();
        $refreshed = $queues->page('reports', QueueActivityTab::Completed, -1);

        expect($refreshed->total)->toBe(2)
            ->and(array_column($refreshed->toArray()['rows'], 'id'))
            ->toBe(['completed-1', 'completed-0']);
    });

    it('returns neutral warming queue data until a cold index is published', function (): void {
        config()->set('horizon-new-dawn.poll_interval', 0);
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: new FailedJobsData(
                repository: $fixture['repository'],
                tags: mockDashboardContract(TagRepository::class),
                jobs: $jobs,
                retryEligibility: new FailedJobRetryEligibility,
                retainedQuery: $fixture['query'],
                filterCatalog: $fixture['catalog'],
            ),
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );

        $warming = $queues->page('default', QueueActivityTab::Completed, -1);

        expect($warming->available)->toBeTrue()
            ->and($warming->warming)->toBeTrue()
            ->and($warming->rows)->toBe([])
            ->and($warming->message)->toBeNull();

        app(DeferredCallbackCollection::class)->invoke();
        $ready = $queues->page('default', QueueActivityTab::Completed, -1);

        expect($ready->available)->toBeTrue()
            ->and($ready->warming)->toBeFalse()
            ->and($ready->total)->toBe(1);
    });

    it('serves a stale queue summary immediately and replaces it after reconciliation', function (): void {
        config()->set('horizon-new-dawn.poll_interval', 5_000);
        Date::setTestNow('2026-07-20 12:00:00 UTC');
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: new FailedJobsData(
                repository: $fixture['repository'],
                tags: mockDashboardContract(TagRepository::class),
                jobs: $jobs,
                retryEligibility: new FailedJobRetryEligibility,
                retainedQuery: $fixture['query'],
                filterCatalog: $fixture['catalog'],
            ),
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );

        foreach (RetainedJobType::cases() as $type) {
            $fixture['index']->synchronize($type);
        }

        $initial = $queues->summary('default');
        app(DeferredCallbackCollection::class)->invoke();
        $redis->zadd('completed_jobs', -2, 'completed-1');
        Date::setTestNow('2026-07-20 12:00:06 UTC');

        $stale = $queues->summary('default');

        expect($initial->completed)->toBe(1)
            ->and($initial->warming)->toBeFalse()
            ->and($stale->completed)->toBe(1)
            ->and($stale->warming)->toBeFalse();

        $redis->afterSourceSnapshotStore = static function (): never {
            throw new RuntimeException('Retained source temporarily unavailable.');
        };
        app(DeferredCallbackCollection::class)->invoke();

        expect($queues->summary('default')->completed)->toBe(1);

        $redis->afterSourceSnapshotStore = null;
        Date::setTestNow('2026-07-20 12:00:12 UTC');
        $queues->summary('default');
        app(DeferredCallbackCollection::class)->invoke();
        $refreshed = $queues->summary('default');

        expect($refreshed->completed)->toBe(2)
            ->and($refreshed->warming)->toBeFalse();
    });

    it('serves a prior cached queue summary while a published pointer is temporarily missing', function (): void {
        config()->set('horizon-new-dawn.poll_interval', 0);
        config()->set('horizon.prefix', 'queue-last-good-summary:');
        app(CacheFactory::class)->store()->clear();
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: new FailedJobsData(
                repository: $fixture['repository'],
                tags: mockDashboardContract(TagRepository::class),
                jobs: $jobs,
                retryEligibility: new FailedJobRetryEligibility,
                retainedQuery: $fixture['query'],
                filterCatalog: $fixture['catalog'],
            ),
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );

        foreach (RetainedJobType::cases() as $type) {
            $fixture['index']->synchronize($type);
        }

        $initial = $queues->summary('default');
        $cache = app(CacheFactory::class)->store();
        $cacheKey = 'horizon-new-dawn:queue-jobs:'.hash(
            'sha256',
            "queue-last-good-summary:\0default",
        );
        $payload = $cache->get($cacheKey);

        if (! is_array($payload)) {
            throw new RuntimeException('The queue summary was not cached.');
        }

        unset($payload['revisions']);
        $cache->forever($cacheKey, $payload);
        unset(
            $redis->strings[retainedPublishedGenerationKey(
                $redis,
                RetainedJobType::Completed,
            )],
        );

        $cached = $queues->summary('default');

        expect($initial->completed)->toBe(1)
            ->and($cached->completed)->toBe(1)
            ->and($cached->warming)->toBeFalse()
            ->and($cached->message)->toBeNull();
    });

    it('returns a neutral queue summary while its retained indexes warm', function (): void {
        config()->set('horizon-new-dawn.poll_interval', 0);
        $redis = new RetainedJobQueryRedisClient;
        $redis->seedSortedSet('completed_jobs', [
            'completed-0' => -1,
        ]);
        $fixture = retainedJobQueryFixture($redis);
        $jobs = new JobsData(
            jobs: $fixture['repository'],
            retainedQuery: $fixture['query'],
            filterCatalog: $fixture['catalog'],
        );
        $queues = new QueueJobsData(
            repository: $fixture['repository'],
            jobs: $jobs,
            failedJobs: new FailedJobsData(
                repository: $fixture['repository'],
                tags: mockDashboardContract(TagRepository::class),
                jobs: $jobs,
                retryEligibility: new FailedJobRetryEligibility,
                retainedQuery: $fixture['query'],
                filterCatalog: $fixture['catalog'],
            ),
            cache: app(CacheFactory::class),
            retainedQuery: $fixture['query'],
        );

        $warming = $queues->summary('default');

        expect($warming->warming)->toBeTrue()
            ->and($warming->message)->toBeNull()
            ->and($warming->completed)->toBeNull();

        app(DeferredCallbackCollection::class)->invoke();
        $ready = $queues->summary('default');

        expect($ready->warming)->toBeFalse()
            ->and($ready->completed)->toBe(1);
    });
});
