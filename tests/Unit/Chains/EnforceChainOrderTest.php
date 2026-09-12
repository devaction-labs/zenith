<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Chains\ChainSequencer;
use DevactionLabs\Zenith\Chains\EnforceChainOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;

final class ChainOrderMiddlewareJob
{
    use InteractsWithQueue, Queueable;
}

/** @param array<string, mixed>|null $chain */
function chainQueueJob(?array $chain): SyncJob
{
    return new SyncJob(app(), json_encode($chain !== null ? ['zenith_chain' => $chain] : [], JSON_THROW_ON_ERROR), 'sync', 'default');
}

it('runs a job with no chain payload straight through', function (): void {
    $middleware = new EnforceChainOrder;
    $job = (new ChainOrderMiddlewareJob)->setJob(chainQueueJob(null));

    $result = $middleware->handle($job, fn (object $job): string => 'ran');

    expect($result)->toBe('ran');
});

it('releases a job that is not next in its chain without running it', function (): void {
    ChainSequencer::assignTicket('orders');
    ChainSequencer::assignTicket('orders');

    $middleware = new EnforceChainOrder;
    $job = (new ChainOrderMiddlewareJob)->setJob(chainQueueJob(['key' => 'orders', 'ticket' => 2]));

    $result = $middleware->handle($job, function (): never {
        throw new RuntimeException('should not run out of turn');
    });

    expect($result)->toBeNull();
});

it('runs the next job in its chain and advances the sequence on success', function (): void {
    ChainSequencer::assignTicket('orders');

    $middleware = new EnforceChainOrder;
    $job = (new ChainOrderMiddlewareJob)->setJob(chainQueueJob(['key' => 'orders', 'ticket' => 1]));

    $result = $middleware->handle($job, fn (object $job): string => 'ran');

    expect($result)->toBe('ran')
        ->and(ChainSequencer::isNext('orders', 2))->toBeTrue();
});

it('leaves the chain advancing to a retried attempt when the job throws', function (): void {
    ChainSequencer::assignTicket('orders');

    $middleware = new EnforceChainOrder;
    $job = (new ChainOrderMiddlewareJob)->setJob(chainQueueJob(['key' => 'orders', 'ticket' => 1]));

    expect(fn (): mixed => $middleware->handle($job, function (): never {
        throw new RuntimeException('attempt failed');
    }))->toThrow(RuntimeException::class, 'attempt failed');

    expect(ChainSequencer::isNext('orders', 1))->toBeTrue();
});
