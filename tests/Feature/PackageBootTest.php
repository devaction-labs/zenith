<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use NckRtl\HorizonNewDawn\Assets\AssetPath;
use NckRtl\HorizonNewDawn\HorizonNewDawnServiceProvider;

describe('package boot', function (): void {
    it('boots the package configuration', function (): void {
        expect(app()->getProvider(HorizonNewDawnServiceProvider::class))->not->toBeNull()
            ->and(app()->environment())->toBe('local')
            ->and(config('horizon-new-dawn.poll_interval'))->toBe(5000)
            ->and(config('horizon-new-dawn'))->not->toHaveKey('assets_path')
            ->and(config('horizon-new-dawn'))->not->toHaveKey('recent_failures_limit')
            ->and(config('horizon-new-dawn.job_navigation_breakdown'))->toBeFalse()
            ->and(config('horizon-new-dawn.bulk_operations.timeout'))->toBeNull();
    });

    it('boots when cached configuration predates the package', function (): void {
        config()->set('horizon-new-dawn', []);

        (new HorizonNewDawnServiceProvider(app()))->boot(app(AssetPath::class));

        expect(app(AssetPath::class)->relative())->toBe('vendor/horizon-new-dawn/build');
    });

    it('registers the package database migrations', function (): void {
        expect(app(Migrator::class)->paths())
            ->toContain(realpath(__DIR__.'/../../database/migrations'));
    });
});
