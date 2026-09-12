<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Zenith;

afterEach(function (): void {
    Zenith::explainFailureUsing(null);
});

it('has no failure explainer registered by default', function (): void {
    expect(Zenith::explainsFailures())->toBeFalse()
        ->and(Zenith::explainFailure('App\\Jobs\\SendInvoice', 'Connection refused'))->toBeNull();
});

it('delegates to the registered failure explainer', function (): void {
    Zenith::explainFailureUsing(
        fn (string $jobClass, string $message): string => "{$jobClass} failed: {$message}",
    );

    expect(Zenith::explainsFailures())->toBeTrue()
        ->and(Zenith::explainFailure('App\\Jobs\\SendInvoice', 'Connection refused'))
        ->toBe('App\\Jobs\\SendInvoice failed: Connection refused');
});

it('can unregister a failure explainer', function (): void {
    Zenith::explainFailureUsing(fn (): string => 'explained');
    Zenith::explainFailureUsing(null);

    expect(Zenith::explainsFailures())->toBeFalse();
});
