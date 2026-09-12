<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Jobs\RetainedJobIndex;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use Laravel\Horizon\Contracts\JobRepository;
use Predis\Client;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\artisan;

it('warms every retained job type explicitly', function (): void {
    app()->instance(
        RetainedJobIndex::class,
        retainedJobIndexForCommandTests(new WarmRetainedJobsRedisClient),
    );

    $command = artisan('zenith:warm-retained-jobs');

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('The warm retained jobs command did not return a pending command.');
    }

    $command
        ->expectsOutputToContain('Warming retained pending jobs')
        ->expectsOutputToContain('Warming retained completed jobs')
        ->expectsOutputToContain('Warming retained silenced jobs')
        ->expectsOutputToContain('Warming retained failed jobs')
        ->expectsOutputToContain('Retained job indexes are warm')
        ->assertSuccessful()
        ->execute();
});

it('registers the warm retained jobs command with Artisan', function (): void {
    expect(Artisan::all())->toHaveKey('zenith:warm-retained-jobs');
});

it('fails when the retained job index namespace is already claimed', function (): void {
    $client = new WarmRetainedJobsRedisClient;
    $index = retainedJobIndexForCommandTests($client);
    $ownerKey = (fn (): string => $this->key('owner'))->call($index);
    $client->strings[$ownerKey] = 'someone-else';
    app()->instance(RetainedJobIndex::class, $index);

    expect(fn (): int => Artisan::call('zenith:warm-retained-jobs'))
        ->toThrow(RuntimeException::class, 'The retained job index Redis namespace is already in use.');
});

final class WarmRetainedJobsRedisClient extends Client
{
    /** @var array<string, array<string, float>> */
    public array $sortedSets = [];

    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    /** @var array<string, array<string, true>> */
    public array $sets = [];

    /** @var array<string, string> */
    public array $strings = [];

    public function __construct()
    {
        parent::__construct(options: ['prefix' => '']);
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
        string $key,
        string $token,
        string $seconds = '',
    ): int {
        if (($this->strings[$key] ?? null) !== $token) {
            return 0;
        }

        if ($seconds !== '') {
            return 1;
        }

        unset($this->strings[$key]);

        return 1;
    }

    public function set(string $key, string $value, mixed ...$arguments): bool
    {
        if (in_array('NX', $arguments, true) && isset($this->strings[$key])) {
            return false;
        }

        $this->strings[$key] = $value;

        return true;
    }

    public function del(string ...$keys): int
    {
        foreach ($keys as $key) {
            unset(
                $this->sortedSets[$key],
                $this->hashes[$key],
                $this->sets[$key],
                $this->strings[$key],
            );
        }

        return 1;
    }

    public function expire(string $key, int $seconds): bool
    {
        return true;
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
        if ($keys === []) {
            $this->sortedSets[$destination] = [];

            return 0;
        }

        $members = $this->sortedSets[$keys[0]] ?? [];

        foreach (array_slice($keys, 1) as $key) {
            $members = array_intersect_key(
                $members,
                $this->sortedSets[$key] ?? [],
            );
        }

        foreach (array_keys($members) as $member) {
            $scores = array_map(
                fn (string $key, int $index): float => ($this->sortedSets[$key][$member] ?? 0)
                    * ($weights[$index] ?? 1),
                $keys,
                array_keys($keys),
            );
            $members[$member] = match (strtolower($aggregate)) {
                'min' => min($scores),
                'max' => max($scores),
                default => array_sum($scores),
            };
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

                $members[$member] = match (strtolower($aggregate)) {
                    'min' => isset($members[$member])
                        ? min($members[$member], $weightedScore)
                        : $weightedScore,
                    'max' => isset($members[$member])
                        ? max($members[$member], $weightedScore)
                        : $weightedScore,
                    default => ($members[$member] ?? 0) + $weightedScore,
                };
            }
        }

        $this->sortedSets[$destination] = $members;

        return count($members);
    }

    public function zcard(string $key): int
    {
        return count($this->sortedSets[$key] ?? []);
    }

    /** @return array<int, string>|array<string, float> */
    public function zrange(
        string $key,
        int $start,
        int $stop,
        mixed $options = null,
    ): array {
        $members = array_slice(
            $this->sortedSets[$key] ?? [],
            $start,
            $stop - $start + 1,
            true,
        );

        return is_array($options) && ($options['withscores'] ?? false)
            ? $members
            : array_keys($members);
    }

    public function zadd(
        string $key,
        float|int|string $score,
        string $member,
    ): int {
        $wasAdded = ! isset($this->sortedSets[$key][$member]);
        $this->sortedSets[$key][$member] = (float) $score;

        return (int) $wasAdded;
    }

    public function zrem(string $key, string ...$members): int
    {
        foreach ($members as $member) {
            unset($this->sortedSets[$key][$member]);
        }

        return 0;
    }

    /** @param array<int, string> $fields
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
        unset($this->hashes[$key][$field]);

        return 1;
    }

    public function hlen(string $key): int
    {
        return count($this->hashes[$key] ?? []);
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
        unset($this->sets[$key][$value]);

        return 1;
    }

    public function scard(string $key): int
    {
        return count($this->sets[$key] ?? []);
    }

    public function sismember(string $key, string $value): bool
    {
        return isset($this->sets[$key][$value]);
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
}

function retainedJobIndexForCommandTests(WarmRetainedJobsRedisClient $client): RetainedJobIndex
{
    $redis = mockDashboardContract(RedisFactory::class);
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardExpects($jobs, 'trimRecentJobs', times: 'zeroOrMoreTimes');
    dashboardExpects($jobs, 'trimFailedJobs', times: 'zeroOrMoreTimes');
    dashboardNeverReceives($jobs, 'getJobs');
    $connection = new PredisConnection($client);
    dashboardReturnsFor($redis, 'connection', ['horizon'], $connection);

    return new RetainedJobIndex($redis, $jobs);
}
