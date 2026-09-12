<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Telemetry\AttemptFingerprint;

function attemptFingerprintFixture(string $message): RuntimeException
{
    return new RuntimeException($message);
}

it('derives the same fingerprint for the same file and line', function (): void {
    $first = attemptFingerprintFixture('boom one');
    $second = attemptFingerprintFixture('a completely different message');

    expect(AttemptFingerprint::for($first))->toBe(AttemptFingerprint::for($second));
});

it('derives a different fingerprint for a different line', function (): void {
    $first = new RuntimeException('boom');
    $second = new RuntimeException('boom');

    expect(AttemptFingerprint::for($first))->not->toBe(AttemptFingerprint::for($second));
});

it('is an eight character lowercase hex string', function (): void {
    $fingerprint = AttemptFingerprint::for(new RuntimeException('boom'));

    expect($fingerprint)->toMatch('/\A[0-9a-f]{8}\z/');
});
