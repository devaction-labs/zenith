<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Tests;

use DevactionLabs\Zenith\ZenithServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishPackageAssets();
    }

    public function rebootstrapApplication(): void
    {
        $this->refreshApplication();
        $this->publishPackageAssets();
    }

    protected function publishPackageAssets(): void
    {
        app(Filesystem::class)->copyDirectory(
            __DIR__.'/../dist/build',
            public_path('vendor/zenith/build'),
        );
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        $providers = [
            InertiaServiceProvider::class,
            LaravelDataServiceProvider::class,
            HorizonServiceProvider::class,
        ];

        if (class_exists(ZenithServiceProvider::class)) {
            $providers[] = ZenithServiceProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        $app->detectEnvironment(static fn (): string => 'local');
        $app['config']->set('app.env', 'local');
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
    }
}
