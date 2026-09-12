<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Support\PayloadRedactor;
use DevactionLabs\Zenith\Zenith;

describe('PayloadRedactor', function (): void {
    afterEach(function (): void {
        Zenith::resetRedactPayloadUsing();
    });

    it('redacts configured key patterns case-insensitively', function (): void {
        config()->set('zenith.redact_payload_keys', ['password', 'token']);

        $redacted = PayloadRedactor::redact([
            'Password' => 'hunter2',
            'apiToken' => 'abc123',
            'username' => 'alex',
        ]);

        expect($redacted)->toBe([
            'Password' => PayloadRedactor::REDACTED_VALUE,
            'apiToken' => PayloadRedactor::REDACTED_VALUE,
            'username' => 'alex',
        ]);
    });

    it('redacts matching keys inside nested arrays', function (): void {
        config()->set('zenith.redact_payload_keys', ['secret']);

        $redacted = PayloadRedactor::redact([
            'data' => [
                'customerId' => 42,
                'billing' => [
                    'clientSecret' => 'sk_live_123',
                ],
            ],
        ]);

        expect($redacted)->toBe([
            'data' => [
                'customerId' => 42,
                'billing' => [
                    'clientSecret' => PayloadRedactor::REDACTED_VALUE,
                ],
            ],
        ]);
    });

    it('ignores non-string configured patterns and blank patterns', function (): void {
        config()->set('zenith.redact_payload_keys', ['', 42, null, 'token']);

        $redacted = PayloadRedactor::redact(['token' => 'abc', 'other' => 'value']);

        expect($redacted)->toBe([
            'token' => PayloadRedactor::REDACTED_VALUE,
            'other' => 'value',
        ]);
    });

    it('defers entirely to a registered redaction callback', function (): void {
        config()->set('zenith.redact_payload_keys', ['password']);

        Zenith::redactPayloadUsing(static fn (array $payload): array => [
            ...$payload,
            'password' => 'untouched-by-default-rules',
            'custom' => 'marker',
        ]);

        $redacted = PayloadRedactor::redact(['password' => 'hunter2']);

        expect($redacted)->toBe([
            'password' => 'untouched-by-default-rules',
            'custom' => 'marker',
        ]);
    });

    it('falls back to the original payload when a registered callback does not return an array', function (): void {
        Zenith::redactPayloadUsing(static fn (array $payload): mixed => 'not-an-array');

        $redacted = PayloadRedactor::redact(['password' => 'hunter2']);

        expect($redacted)->toBe(['password' => 'hunter2']);
    });

    it('keeps only string keys from a registered callback result', function (): void {
        Zenith::redactPayloadUsing(static fn (array $payload): array => [
            'safe' => 'value',
            0 => 'dropped',
        ]);

        $redacted = PayloadRedactor::redact(['anything' => 'value']);

        expect($redacted)->toBe(['safe' => 'value']);
    });
});
