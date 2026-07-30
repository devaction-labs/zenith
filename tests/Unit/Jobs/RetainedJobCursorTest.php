<?php

declare(strict_types=1);

use NckRtl\HorizonNewDawn\Jobs\RetainedJobCursor;
use NckRtl\HorizonNewDawn\Jobs\RetainedJobPosition;
use NckRtl\HorizonNewDawn\Jobs\RetainedJobType;

function retainedJobCursorTestBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @param array<string, mixed> $overrides */
function retainedJobCursorTestPayload(array $overrides = []): string
{
    $payload = array_replace([
        'version' => 4,
        'type' => RetainedJobType::Completed->value,
        'query' => 'query-signature',
        'score' => '-1785067200.123456',
        'id' => 'job-75',
        'offset' => 50,
    ], $overrides);

    return retainedJobCursorTestBase64UrlEncode(
        json_encode($payload, JSON_THROW_ON_ERROR),
    );
}

describe('RetainedJobCursor', function (): void {
    it('round trips an opaque score and id position', function (): void {
        config()->set('app.key', 'base64:production-cursor-test-key');
        $codec = new RetainedJobCursor;
        $position = new RetainedJobPosition(-1785067200.123456, 'job-75', 50);

        $encoded = $codec->encode(
            RetainedJobType::Completed,
            'query-signature',
            $position,
        );
        $decoded = $codec->decode(
            $encoded,
            RetainedJobType::Completed,
            'query-signature',
        );

        expect($encoded)->not->toContain('job-75')
            ->and($decoded)->toEqual($position);
    });

    it('rejects tampering and cross-query reuse', function (): void {
        config()->set('app.key', 'base64:production-cursor-test-key');
        $codec = new RetainedJobCursor;
        $encoded = $codec->encode(
            RetainedJobType::Pending,
            'pending-query',
            new RetainedJobPosition(-100.5, 'job-50', 50),
        );

        expect(fn (): ?RetainedJobPosition => $codec->decode(
            $encoded.'changed',
            RetainedJobType::Pending,
            'pending-query',
        ))->toThrow(RuntimeException::class)
            ->and(fn (): ?RetainedJobPosition => $codec->decode(
                $encoded,
                RetainedJobType::Pending,
                'different-query',
            ))->toThrow(RuntimeException::class)
            ->and(fn (): ?RetainedJobPosition => $codec->decode(
                $encoded,
                RetainedJobType::Failed,
                'pending-query',
            ))->toThrow(RuntimeException::class);
    });

    it('rejects structurally malformed signed cursors', function (
        string $encodedPayload,
    ): void {
        $signingKey = 'base64:production-cursor-test-key';
        config()->set('app.key', $signingKey);
        $signature = hash_hmac('sha256', $encodedPayload, $signingKey, true);
        $cursor = $encodedPayload.'.'.retainedJobCursorTestBase64UrlEncode($signature);

        expect(fn (): ?RetainedJobPosition => (new RetainedJobCursor)->decode(
            $cursor,
            RetainedJobType::Completed,
            'query-signature',
        ))->toThrow(RuntimeException::class);
    })->with([
        'invalid base64 payload' => ['*'],
        'invalid JSON payload' => [
            retainedJobCursorTestBase64UrlEncode('{'),
        ],
        'wrong version' => [
            retainedJobCursorTestPayload(['version' => 2]),
        ],
        'empty ID' => [
            retainedJobCursorTestPayload(['id' => '']),
        ],
        'non-integer offset' => [
            retainedJobCursorTestPayload(['offset' => '50']),
        ],
        'missing score' => [
            retainedJobCursorTestPayload(['score' => null]),
        ],
    ]);

    it('treats empty and legacy first-page sentinels as the first page', function (
        int|string|null $cursor,
    ): void {
        config()->set('app.key', 'base64:production-cursor-test-key');

        expect((new RetainedJobCursor)->decode(
            $cursor,
            RetainedJobType::Completed,
            'query',
        ))->toBeNull();
    })->with([null, '', -1, '-1']);
});
