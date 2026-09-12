<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Limits\Attributes\GlobalLimit;
use DevactionLabs\Zenith\Limits\Attributes\Partition;
use DevactionLabs\Zenith\Limits\Attributes\RateLimit;
use DevactionLabs\Zenith\Limits\LimitAttributes;
use DevactionLabs\Zenith\Limits\LimiterState;
use DevactionLabs\Zenith\Limits\QueueBudget;

#[GlobalLimit(limit: 2, expiresAfter: 60)]
#[RateLimit(allowed: 5, per: 60)]
#[Partition('accountId')]
final class StatefulLimitedJob
{
    public function __construct(public string $accountId) {}
}

final class StatelessLimitedJob {}

it('reports null state for a job class without limiting attributes', function (): void {
    $state = (new LimiterState(app(QueueBudget::class)))->forJob(StatelessLimitedJob::class);

    expect($state->jobClass)->toBe(StatelessLimitedJob::class)
        ->and($state->globalLimit)->toBeNull()
        ->and($state->globalInUse)->toBeNull()
        ->and($state->globalRemaining)->toBeNull()
        ->and($state->rateAllowed)->toBeNull()
        ->and($state->ratePeriod)->toBeNull()
        ->and($state->rateRemaining)->toBeNull();
});

it('reports current global and rate limiter usage for a partitioned job class', function (): void {
    $budget = app(QueueBudget::class);
    $attributes = LimitAttributes::of(StatefulLimitedJob::class);

    $budget->acquireSlot($attributes->concurrencyName(StatefulLimitedJob::class, 'acc-1'), 2);
    $budget->consume($attributes->budgetName(StatefulLimitedJob::class), allowed: 5, period: 60, partition: 'acc-1');

    $state = (new LimiterState($budget))->forJob(StatefulLimitedJob::class, 'acc-1');

    expect($state->partition)->toBe('acc-1')
        ->and($state->globalLimit)->toBe(2)
        ->and($state->globalInUse)->toBe(1)
        ->and($state->globalRemaining)->toBe(1)
        ->and($state->rateAllowed)->toBe(5)
        ->and($state->ratePeriod)->toBe(60)
        ->and($state->rateRemaining)->toBe(4);
});

it('keeps limiter state independent per partition', function (): void {
    $budget = app(QueueBudget::class);
    $attributes = LimitAttributes::of(StatefulLimitedJob::class);

    $budget->acquireSlot($attributes->concurrencyName(StatefulLimitedJob::class, 'acc-1'), 2);

    $state = (new LimiterState($budget))->forJob(StatefulLimitedJob::class, 'acc-2');

    expect($state->globalInUse)->toBe(0)
        ->and($state->globalRemaining)->toBe(2);
});
