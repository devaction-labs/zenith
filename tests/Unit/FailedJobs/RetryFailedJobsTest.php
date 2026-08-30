<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\FailedJobs\Actions\RetryFailedJob;
use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobRetryEligibility;
use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobRetryLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Bus;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Jobs\RetryFailedJob as HorizonRetryFailedJob;

use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardExpects;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturns;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\HorizonNewDawn\Tests\Support\horizonJob;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

function failedJobRetryLock(
    FailedJobRetryLockRedisConnection $connection,
): FailedJobRetryLock {
    $redis = mockDashboardContract(RedisFactory::class);
    dashboardReturns($redis, 'connection', $connection);

    return new FailedJobRetryLock($redis);
}

it('serializes overlapping retries for the same retained failed job', function (): void {
    $connection = new FailedJobRetryLockRedisConnection;
    $job = horizonJob(0, 'failed-1');
    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'findFailed', ['failed-1'], $job);

    $nestedResult = true;
    $holder = new FailedJobRetryActionHolder;
    $bus = mockDashboardContract(Dispatcher::class);
    dashboardExpects(
        $bus,
        'dispatch',
        returnUsing: function (mixed $command) use ($holder, &$nestedResult): void {
            expect($command)->toBeInstanceOf(HorizonRetryFailedJob::class)
                ->and($command->id)->toBe('failed-1');

            if (! $holder->action instanceof RetryFailedJob) {
                throw new LogicException('The retry action was not initialized.');
            }

            $nestedResult = $holder->action->handleBulk('failed-1');
        },
    );

    $holder->action = new RetryFailedJob(
        $bus,
        $repository,
        new FailedJobRetryEligibility,
        failedJobRetryLock($connection),
    );

    expect($holder->action->handleBulk('failed-1'))->toBeTrue();
    expect($nestedResult)->toBeFalse();
    expect($connection->locks)->toBe([]);
});

it('rechecks retry eligibility inside the distributed lock', function (): void {
    $supplied = horizonJob(0, 'failed-1');
    $current = clone $supplied;
    $current->retried_by = json_encode([
        ['id' => 'active-retry', 'status' => 'pending'],
    ], JSON_THROW_ON_ERROR);

    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'findFailed', ['failed-1'], $current);
    $bus = mockDashboardContract(Dispatcher::class);
    $bus->shouldNotReceive('dispatch');

    $action = new RetryFailedJob(
        $bus,
        $repository,
        new FailedJobRetryEligibility,
        failedJobRetryLock(new FailedJobRetryLockRedisConnection),
    );

    expect($action->handleBulk('failed-1', $supplied))->toBeFalse();
});

it('fails closed when the retry lock cannot be acquired', function (): void {
    $connection = new FailedJobRetryLockRedisConnection;
    $connection->failAcquisition = true;
    $repository = mockDashboardContract(JobRepository::class);
    dashboardNeverReceives($repository, 'findFailed');
    $bus = mockDashboardContract(Dispatcher::class);
    $bus->shouldNotReceive('dispatch');

    $action = new RetryFailedJob(
        $bus,
        $repository,
        new FailedJobRetryEligibility,
        failedJobRetryLock($connection),
    );

    expect(fn (): bool => $action->handleBulk('failed-1'))
        ->toThrow(RuntimeException::class, 'Retry lock acquisition failed.');
});

it('does not report a completed retry as failed when lock cleanup fails', function (): void {
    $connection = new FailedJobRetryLockRedisConnection;
    $connection->failRelease = true;
    $job = horizonJob(0, 'failed-1');
    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'findFailed', ['failed-1'], $job);
    $bus = mockDashboardContract(Dispatcher::class);
    dashboardExpects($bus, 'dispatch');

    $action = new RetryFailedJob(
        $bus,
        $repository,
        new FailedJobRetryEligibility,
        failedJobRetryLock($connection),
    );

    expect($action->handleBulk('failed-1'))->toBeTrue()
        ->and($connection->locks)->not->toBe([]);
});

it('dispatches one supported Horizon retry job', function (): void {
    Bus::fake();

    $job = horizonJob(0, 'failed-1');
    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'findFailed', ['failed-1'], $job);

    $scheduled = (new RetryFailedJob(
        app(Dispatcher::class),
        $repository,
        new FailedJobRetryEligibility,
    ))->handle('failed-1');

    expect($scheduled)->toBeTrue();
    Bus::assertDispatched(
        HorizonRetryFailedJob::class,
        fn (HorizonRetryFailedJob $job): bool => $job->id === 'failed-1',
    );
});

it('allows jobs that Horizon reports without retry metadata', function (): void {
    $job = (object) get_object_vars(horizonJob(0, 'never-retried'));
    $job->retried_by = false;

    expect((new FailedJobRetryEligibility)->allows($job))->toBeTrue();
});

it('allows individual retries after prior retries failed and blocks active or successful retries', function (): void {
    Bus::fake();

    $alreadyRetried = horizonJob(0, 'already-retried');
    $alreadyRetried->retried_by = json_encode([
        ['id' => 'failed-retry', 'status' => 'failed'],
    ], JSON_THROW_ON_ERROR);

    $neverRetried = horizonJob(0, 'never-retried');
    $neverRetried->retried_by = '[]';

    $retryChild = horizonJob(1, 'retry-child');
    $retryPayload = json_decode($retryChild->payload, true, flags: JSON_THROW_ON_ERROR);
    $retryChild->payload = json_encode([...$retryPayload, 'retry_of' => 'original'], JSON_THROW_ON_ERROR);

    $completed = horizonJob(2, 'completed-retry');
    $completed->retried_by = json_encode([
        ['id' => 'completed-child', 'status' => 'completed'],
    ], JSON_THROW_ON_ERROR);

    $pending = horizonJob(3, 'pending-retry');
    $pending->retried_by = json_encode([
        ['id' => 'pending-child', 'status' => 'pending'],
    ], JSON_THROW_ON_ERROR);

    $unknown = horizonJob(4, 'unknown-retry');
    $unknown->retried_by = json_encode([
        ['id' => 'unknown-child', 'status' => 'reserved'],
    ], JSON_THROW_ON_ERROR);

    $malformed = horizonJob(5, 'malformed-retries');
    $malformed->retried_by = '{not-json';

    $repository = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($repository, 'findFailed', ['already-retried'], $alreadyRetried);
    dashboardReturnsFor($repository, 'findFailed', ['never-retried'], $neverRetried);
    dashboardReturnsFor($repository, 'findFailed', ['retry-child'], $retryChild);
    dashboardReturnsFor($repository, 'findFailed', ['completed-retry'], $completed);
    dashboardReturnsFor($repository, 'findFailed', ['pending-retry'], $pending);
    dashboardReturnsFor($repository, 'findFailed', ['unknown-retry'], $unknown);
    dashboardReturnsFor($repository, 'findFailed', ['malformed-retries'], $malformed);
    dashboardReturnsFor($repository, 'findFailed', ['missing'], null);

    $action = new RetryFailedJob(
        app(Dispatcher::class),
        $repository,
        new FailedJobRetryEligibility,
    );

    expect($action->handle('already-retried'))->toBeTrue()
        ->and($action->handle('never-retried'))->toBeTrue()
        ->and($action->handle('retry-child'))->toBeTrue()
        ->and($action->handle('completed-retry'))->toBeFalse()
        ->and($action->handle('pending-retry'))->toBeFalse()
        ->and($action->handle('unknown-retry'))->toBeFalse()
        ->and($action->handle('malformed-retries'))->toBeFalse()
        ->and($action->handle('missing'))->toBeFalse();

    Bus::assertDispatchedTimes(HorizonRetryFailedJob::class, 3);
    Bus::assertDispatched(
        HorizonRetryFailedJob::class,
        fn (HorizonRetryFailedJob $job): bool => $job->id === 'already-retried',
    );
    Bus::assertDispatched(
        HorizonRetryFailedJob::class,
        fn (HorizonRetryFailedJob $job): bool => $job->id === 'retry-child',
    );
});

final class FailedJobRetryLockRedisConnection extends Connection
{
    /** @var array<string, string> */
    public array $locks = [];

    public bool $failAcquisition = false;

    public bool $failRelease = false;

    public function __construct() {}

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, $callback, $method = 'subscribe'): void {}

    public function eval(
        string $script,
        int $keyCount,
        string $key,
        string $token,
        string $seconds = '',
    ): int|string|false {
        if (str_contains($script, "'set'")) {
            if ($this->failAcquisition) {
                throw new RuntimeException('Retry lock acquisition failed.');
            }

            if (isset($this->locks[$key])) {
                return false;
            }

            $this->locks[$key] = $token;

            return 'OK';
        }

        if ($this->failRelease) {
            throw new RuntimeException('Retry lock release failed.');
        }

        if (($this->locks[$key] ?? null) !== $token) {
            return 0;
        }

        unset($this->locks[$key]);

        return 1;
    }
}

final class FailedJobRetryActionHolder
{
    public ?RetryFailedJob $action = null;
}
