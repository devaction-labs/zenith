<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Assets\AssetPath;
use DevactionLabs\Zenith\ZenithServiceProvider;
use Illuminate\Database\Migrations\Migrator;

describe('package boot', function (): void {
    it('boots the package configuration', function (): void {
        expect(app()->getProvider(ZenithServiceProvider::class))->not->toBeNull()
            ->and(app()->environment())->toBe('local')
            ->and(config('zenith.poll_interval'))->toBe(5000)
            ->and(config('zenith'))->not->toHaveKey('assets_path')
            ->and(config('zenith'))->not->toHaveKey('recent_failures_limit')
            ->and(config('zenith.job_navigation_breakdown'))->toBeFalse()
            ->and(config('zenith.bulk_operations.timeout'))->toBeNull();
    });

    it('boots when cached configuration predates the package', function (): void {
        config()->set('zenith', []);

        (new ZenithServiceProvider(app()))->boot(app(AssetPath::class));

        expect(app(AssetPath::class)->relative())->toBe('vendor/zenith/build');
    });

    it('registers the package database migrations', function (): void {
        expect(app(Migrator::class)->paths())
            ->toContain(realpath(__DIR__.'/../../database/migrations'));
    });
});
