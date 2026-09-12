<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Chains\Attributes\ChainBy;
use DevactionLabs\Zenith\Chains\ChainPayloadHook;
use DevactionLabs\Zenith\Chains\ChainSequencer;

#[ChainBy('nightly-report')]
final class HookedChainJob {}

final class HookedPlainJob {}

it('assigns and returns a chain ticket for a job carrying a chain declaration', function (): void {
    $hook = new ChainPayloadHook;

    $payload = $hook('sync', 'default', ['data' => ['command' => new HookedChainJob]]);

    expect($payload)->toBe(['zenith_chain' => ['key' => 'nightly-report', 'ticket' => 1]]);

    $payload = $hook('sync', 'default', ['data' => ['command' => new HookedChainJob]]);

    expect($payload)->toBe(['zenith_chain' => ['key' => 'nightly-report', 'ticket' => 2]])
        ->and(ChainSequencer::isNext('nightly-report', 1))->toBeTrue();
});

it('adds nothing for a job with no chain declaration', function (): void {
    $hook = new ChainPayloadHook;

    expect($hook('sync', 'default', ['data' => ['command' => new HookedPlainJob]]))->toBe([]);
});

it('adds nothing when the payload has no command object', function (): void {
    $hook = new ChainPayloadHook;

    expect($hook('sync', 'default', ['data' => ['command' => 'HookedPlainJob@handle']]))->toBe([]);
});
