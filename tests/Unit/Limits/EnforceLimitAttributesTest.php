<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Limits\Attributes\GlobalLimit;
use DevactionLabs\Zenith\Limits\Attributes\Partition;
use DevactionLabs\Zenith\Limits\Attributes\RateLimit;
use DevactionLabs\Zenith\Limits\EnforceLimitAttributes;
use DevactionLabs\Zenith\Limits\QueueBudget;
use DevactionLabs\Zenith\Tests\Unit\Signals\InterleavingArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Sleep;

#[GlobalLimit(limit: 1, expiresAfter: 60)]
final class SoleSlotAttributeJob
{
    /** @var list<int> */
    public array $releases = [];

    public function release(int $delay = 0): void
    {
        $this->releases[] = $delay;
    }
}

#[RateLimit(allowed: 1, per: 60)]
final class RateLimitedAttributeJob
{
    /** @var list<int> */
    public array $releases = [];

    public function release(int $delay = 0): void
    {
        $this->releases[] = $delay;
    }
}

#[GlobalLimit(limit: 1, expiresAfter: 60)]
#[Partition('accountId')]
final class PartitionedAttributeJob
{
    /** @var list<int> */
    public array $releases = [];

    public function __construct(public string $accountId) {}

    public function release(int $delay = 0): void
    {
        $this->releases[] = $delay;
    }
}

final class UnattributedAttributeJob
{
    /** @var list<int> */
    public array $releases = [];

    public function release(int $delay = 0): void
    {
        $this->releases[] = $delay;
    }
}

it('runs a job with no limiting attributes straight through', function (): void {
    $middleware = new EnforceLimitAttributes(app(QueueBudget::class));

    $result = $middleware->handle(new UnattributedAttributeJob, fn (object $job): string => 'ran');

    expect($result)->toBe('ran');
});

it('enforces the global concurrency limit declared on the job class', function (): void {
    $budget = app(QueueBudget::class);
    $middleware = new EnforceLimitAttributes($budget);

    $first = $middleware->handle(new SoleSlotAttributeJob, fn (object $job): string => 'first');
    expect($first)->toBe('first');

    $budget->acquireSlot('SoleSlotAttributeJob', 1);
    $job = new SoleSlotAttributeJob;

    $second = $middleware->handle($job, fn (object $job): string => 'second');

    expect($second)->toBeNull()
        ->and($job->releases)->not->toBe([]);
});

it('enforces the rate limit declared on the job class', function (): void {
    $budget = app(QueueBudget::class);
    $middleware = new EnforceLimitAttributes($budget);

    $first = $middleware->handle(new RateLimitedAttributeJob, fn (object $job): string => 'ran');
    expect($first)->toBe('ran');

    $job = new RateLimitedAttributeJob;
    $second = $middleware->handle($job, fn (object $job): string => 'ran again');

    expect($second)->toBeNull()
        ->and($job->releases)->not->toBe([]);
});

it('partitions the global limit by the named constructor property', function (): void {
    $budget = app(QueueBudget::class);
    $middleware = new EnforceLimitAttributes($budget);

    $accountOneFirst = $middleware->handle(
        new PartitionedAttributeJob('account-one'),
        fn (object $job): string => 'ran',
    );
    $accountTwoFirst = $middleware->handle(
        new PartitionedAttributeJob('account-two'),
        fn (object $job): string => 'ran',
    );

    expect($accountOneFirst)->toBe('ran')
        ->and($accountTwoFirst)->toBe('ran');
});

it('lets only one concurrently dispatched job hold a global limit slot at a time', function (): void {
    InterleavingArrayStore::register('global-limit-interleave');
    $budget = new QueueBudget(app(RateLimiter::class), app(CacheFactory::class)->store('global-limit-interleave'));
    $middleware = new EnforceLimitAttributes($budget);

    $concurrent = 0;
    $maxConcurrent = 0;
    $results = [];

    $run = function () use ($middleware, &$concurrent, &$maxConcurrent, &$results): void {
        $job = new SoleSlotAttributeJob;

        $results[] = $middleware->handle($job, function (object $job) use (&$concurrent, &$maxConcurrent): string {
            $concurrent++;
            $maxConcurrent = max($maxConcurrent, $concurrent);
            Sleep::usleep(1);
            $concurrent--;

            return 'ran';
        });
    };

    InterleavingArrayStore::interleave([$run, $run]);

    expect($maxConcurrent)->toBe(1)
        ->and(array_values(array_filter($results, static fn (mixed $result): bool => $result === 'ran')))->toHaveCount(1)
        ->and(array_values(array_filter($results, static fn (mixed $result): bool => $result === null)))->toHaveCount(1);
});
