<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Chains\Attributes\ChainBy;
use DevactionLabs\Zenith\Chains\ChainKey;

#[ChainBy('accountId')]
final class PropertyChainedJob
{
    public function __construct(public string $accountId) {}
}

#[ChainBy('nightly-report')]
final class LiteralChainedJob
{
    public function __construct(public string $accountId) {}
}

final class FluentChainedJob
{
    public function chainKey(): string
    {
        return 'fluent-key';
    }
}

final class UnchainedJob {}

it('reads the runtime value of the named constructor property as the chain key', function (): void {
    expect(ChainKey::resolve(new PropertyChainedJob('acc-1')))->toBe('acc-1');
});

it('falls back to the attribute value itself when no matching property exists', function (): void {
    expect(ChainKey::resolve(new LiteralChainedJob('acc-1')))->toBe('nightly-report');
});

it('prefers a fluent chainKey method over the attribute', function (): void {
    expect(ChainKey::resolve(new FluentChainedJob))->toBe('fluent-key');
});

it('returns null for a job with no chain declaration', function (): void {
    expect(ChainKey::resolve(new UnchainedJob))->toBeNull();
});
