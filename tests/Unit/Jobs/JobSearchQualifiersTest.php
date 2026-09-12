<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Jobs\JobSearchQualifiers;

describe('JobSearchQualifiers', function (): void {
    it('returns no tag and no remainder for an empty search', function (): void {
        $parsed = JobSearchQualifiers::parse(null);

        expect($parsed->tag)->toBeNull()
            ->and($parsed->remainder)->toBeNull();
    });

    it('extracts a leading tag: qualifier from an otherwise-empty search', function (): void {
        $parsed = JobSearchQualifiers::parse('tag:tenant:42');

        expect($parsed->tag)->toBe('tenant:42')
            ->and($parsed->remainder)->toBeNull();
    });

    it('keeps the remaining terms as the fallback partial-class or exact-ID search', function (): void {
        $parsed = JobSearchQualifiers::parse('tag:vip ImportFeed');

        expect($parsed->tag)->toBe('vip')
            ->and($parsed->remainder)->toBe('ImportFeed');
    });

    it('finds the qualifier no matter where it appears in the search', function (): void {
        $parsed = JobSearchQualifiers::parse('ImportFeed tag:vip');

        expect($parsed->tag)->toBe('vip')
            ->and($parsed->remainder)->toBe('ImportFeed');
    });

    it('only honors the first tag: qualifier and treats the rest as plain text', function (): void {
        $parsed = JobSearchQualifiers::parse('tag:vip tag:beta');

        expect($parsed->tag)->toBe('vip')
            ->and($parsed->remainder)->toBe('tag:beta');
    });

    it('treats a bare "tag:" token as plain text instead of an empty qualifier', function (): void {
        $parsed = JobSearchQualifiers::parse('tag:');

        expect($parsed->tag)->toBeNull()
            ->and($parsed->remainder)->toBe('tag:');
    });

    it('leaves an ordinary search untouched', function (): void {
        $parsed = JobSearchQualifiers::parse('App\\Jobs\\ImportFeed');

        expect($parsed->tag)->toBeNull()
            ->and($parsed->remainder)->toBe('App\\Jobs\\ImportFeed');
    });
});
