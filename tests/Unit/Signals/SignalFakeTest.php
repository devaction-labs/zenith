<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Signals\Signal;

it('lets signals be sent and pulled purely in memory once faked', function (): void {
    config()->set('zenith.signals.store', 'nonexistent-store-that-would-otherwise-blow-up');

    Signal::fake();
    Signal::send('approval', ['ok' => true]);

    expect(Signal::pull('approval'))->toBe(['ok' => true])
        ->and(Signal::pull('approval'))->toBeNull();
});

it('keeps a faked signal working with Signal::await', function (): void {
    Signal::fake();
    Signal::send('handoff', ['value' => 42]);

    expect(Signal::await('handoff', seconds: 5))->toBe(['value' => 42]);
});

it('gives every fake() call a clean slate', function (): void {
    Signal::fake();
    Signal::send('leftover', ['stale' => true]);

    Signal::fake();

    expect(Signal::pull('leftover'))->toBeNull();
});
