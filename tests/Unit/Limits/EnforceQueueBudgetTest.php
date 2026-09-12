<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Limits\EnforceQueueBudget;
use DevactionLabs\Zenith\Limits\EnforceQueueConcurrency;
use DevactionLabs\Zenith\Limits\QueueBudget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

final class QueueBudgetReleasableJob
{
    /** @var list<int> */
    public array $releases = [];

    public function release(int $delay = 0): void
    {
        $this->releases[] = $delay;
    }
}

final class QueueBudgetedJob implements ShouldQueue
{
    use Queueable;

    public static int $runs = 0;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new EnforceQueueBudget(app(QueueBudget::class), 'reports', allowed: 5, period: 60),
            new EnforceQueueConcurrency(app(QueueBudget::class), 'reports', limit: 1),
        ];
    }

    public function handle(): void
    {
        self::$runs++;
    }
}

beforeEach(function (): void {
    QueueBudgetedJob::$runs = 0;
});

it('runs a queued job through the budget middleware and frees its slot', function (): void {
    Bus::dispatch(new QueueBudgetedJob);

    expect(QueueBudgetedJob::$runs)->toBe(1)
        ->and(app(QueueBudget::class)->acquireSlot('reports', 1))->toBeTrue();
});

it('runs the job while the budget allows it', function (): void {
    $middleware = new EnforceQueueBudget(app(QueueBudget::class), 'reports', allowed: 1, period: 60);

    expect($middleware->handle(new QueueBudgetReleasableJob, fn (object $job): string => 'ran'))->toBe('ran');
});

it('releases a throttled job until the rate window resets', function (): void {
    $this->freezeTime();
    $budget = app(QueueBudget::class);
    $budget->consume('reports', allowed: 1, period: 60);
    $this->travel(20)->seconds();
    $job = new QueueBudgetReleasableJob;

    $result = (new EnforceQueueBudget($budget, 'reports', allowed: 1, period: 60))
        ->handle($job, fn (object $job): string => 'ran');

    expect($result)->toBeNull()
        ->and($job->releases)->toBe([40]);
});

it('releases a throttled job after a custom delay', function (): void {
    $budget = app(QueueBudget::class);
    $budget->consume('reports', allowed: 1, period: 60);
    $job = new QueueBudgetReleasableJob;

    (new EnforceQueueBudget($budget, 'reports', allowed: 1, period: 60))
        ->releaseAfter(15)
        ->handle($job, fn (object $job): string => 'ran');

    expect($job->releases)->toBe([15]);
});

it('drops a throttled job instead of releasing it when asked', function (): void {
    $budget = app(QueueBudget::class);
    $budget->consume('reports', allowed: 1, period: 60);
    $job = new QueueBudgetReleasableJob;

    $result = (new EnforceQueueBudget($budget, 'reports', allowed: 1, period: 60))
        ->dontRelease()
        ->handle($job, fn (object $job): string => 'ran');

    expect($result)->toBeNull()
        ->and($job->releases)->toBe([]);
});

it('releases the concurrency slot when the job throws', function (): void {
    $budget = app(QueueBudget::class);
    $middleware = new EnforceQueueConcurrency($budget, 'imports', limit: 1);

    expect(fn (): mixed => $middleware->handle(
        new QueueBudgetReleasableJob,
        fn (object $job): never => throw new RuntimeException('Import failed.'),
    ))->toThrow(RuntimeException::class, 'Import failed.');

    expect($budget->acquireSlot('imports', 1))->toBeTrue();
});

it('releases a job while every concurrency slot is taken', function (): void {
    $budget = app(QueueBudget::class);
    $budget->acquireSlot('imports', 1);
    $job = new QueueBudgetReleasableJob;

    $result = (new EnforceQueueConcurrency($budget, 'imports', limit: 1))
        ->handle($job, fn (object $job): string => 'ran');

    expect($result)->toBeNull()
        ->and($job->releases)->toBe([10])
        ->and($budget->acquireSlot('imports', 1))->toBeFalse();
});
