<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Chains\ChainFailureListener;
use DevactionLabs\Zenith\Chains\ChainSequencer;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;

/** @param array<string, mixed>|null $chain */
function failedChainQueueJob(?array $chain): SyncJob
{
    return new SyncJob(app(), json_encode($chain !== null ? ['zenith_chain' => $chain] : [], JSON_THROW_ON_ERROR), 'sync', 'default');
}

it('advances the chain when a job carrying a chain ticket permanently fails', function (): void {
    ChainSequencer::assignTicket('orders');
    ChainSequencer::assignTicket('orders');

    $listener = new ChainFailureListener;
    $event = new JobFailed('sync', failedChainQueueJob(['key' => 'orders', 'ticket' => 1]), new RuntimeException('boom'));

    $listener->handle($event);

    expect(ChainSequencer::isNext('orders', 2))->toBeTrue();
});

it('does nothing for a failed job that carries no chain ticket', function (): void {
    $listener = new ChainFailureListener;
    $event = new JobFailed('sync', failedChainQueueJob(null), new RuntimeException('boom'));

    $listener->handle($event);
})->throwsNoExceptions();
