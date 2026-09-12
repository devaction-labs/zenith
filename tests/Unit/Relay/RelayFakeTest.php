<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Relay\Relay;
use DevactionLabs\Zenith\Relay\RelayFailedException;

it('records and awaits a relayed result purely in memory once faked', function (): void {
    config()->set('zenith.relay.store', 'nonexistent-store-that-would-otherwise-blow-up');

    Relay::fake();
    Relay::record('job-1', ['ok' => true]);

    expect(Relay::await('job-1', seconds: 5))->toBe(['ok' => true]);
});

it('lets a faked relay report a failure', function (): void {
    Relay::fake();
    Relay::fail('job-2', 'boom');

    expect(fn () => Relay::await('job-2', seconds: 5))
        ->toThrow(RelayFailedException::class, 'boom');
});

it('gives every fake() call a clean slate', function (): void {
    Relay::fake();
    Relay::record('leftover', 'stale');

    Relay::fake();

    expect(fn () => Relay::await('leftover', seconds: 0))->toThrow(Exception::class);
});
