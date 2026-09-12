<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Limits\QueueBudget;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Event;

it('resets the rate window under continuous load', function (): void {
    $this->freezeTime();
    $budget = app(QueueBudget::class);

    expect($budget->consume('api', allowed: 2, period: 60))->toBeTrue();

    $this->travel(50)->seconds();

    expect($budget->consume('api', allowed: 2, period: 60))->toBeTrue()
        ->and($budget->consume('api', allowed: 2, period: 60))->toBeFalse();

    $this->travel(20)->seconds();

    expect($budget->consume('api', allowed: 2, period: 60))->toBeTrue()
        ->and($budget->availableIn('api'))->toBe(60);
});

it('does not admit a racing consumer beyond the budget', function (): void {
    $budget = app(QueueBudget::class);
    $otherWorker = new QueueBudget(app(RateLimiter::class), app(Repository::class));
    $raced = false;

    Event::listen(CacheMissed::class, function () use (&$raced, $otherWorker): void {
        if ($raced) {
            return;
        }

        $raced = true;

        expect($otherWorker->consume('api', allowed: 1, period: 60))->toBeTrue();
    });

    expect($budget->consume('api', allowed: 1, period: 60))->toBeFalse()
        ->and($raced)->toBeTrue()
        ->and($budget->allows('api', allowed: 1, period: 60))->toBeFalse();
});

it('admits as many concurrent holders as there are slots', function (): void {
    $budget = app(QueueBudget::class);

    expect($budget->acquireSlot('imports', 2))->toBeTrue()
        ->and($budget->acquireSlot('imports', 2))->toBeTrue()
        ->and($budget->acquireSlot('imports', 2))->toBeFalse();

    $budget->releaseSlot('imports');

    expect($budget->acquireSlot('imports', 2))->toBeTrue();
});

it('frees a slot held by a crashed worker once it expires', function (): void {
    $this->freezeTime();
    $crashedWorker = new QueueBudget(app(RateLimiter::class), app(Repository::class));
    $budget = app(QueueBudget::class);

    expect($crashedWorker->acquireSlot('imports', 1, expiresAfter: 30))->toBeTrue()
        ->and($budget->acquireSlot('imports', 1))->toBeFalse();

    $this->travel(31)->seconds();

    expect($budget->acquireSlot('imports', 1))->toBeTrue();
});

it('never releases a slot held by another worker', function (): void {
    $otherWorker = new QueueBudget(app(RateLimiter::class), app(Repository::class));
    $budget = app(QueueBudget::class);

    expect($otherWorker->acquireSlot('imports', 1))->toBeTrue();

    $budget->releaseSlot('imports');

    expect($budget->acquireSlot('imports', 1))->toBeFalse();
});

it('shares one budget per container scope so a job can release its slot', function (): void {
    $first = app(QueueBudget::class);

    expect(app(QueueBudget::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(QueueBudget::class))->not->toBe($first);
});
