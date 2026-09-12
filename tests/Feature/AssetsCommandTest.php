<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

afterEach(function (): void {
    $filesystem = app(Filesystem::class);

    $filesystem->deleteDirectory(public_path('vendor/zenith'));
    $filesystem->deleteDirectory(dirname(public_path()).'/zenith-outside-test');
});

it('publishes compiled assets without publishing configuration', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/zenith/build');
    $configPath = config_path('zenith.php');
    $source = dirname(__DIR__, 2).'/dist/build';

    $filesystem->delete($configPath);
    $filesystem->deleteDirectory(public_path('vendor/zenith'));

    expect(Artisan::call('zenith:assets', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Zenith assets are ready')
        ->and($destination.'/manifest.json')->toBeFile()
        ->and($destination.'/favicon.svg')->not->toBeFile()
        ->and($configPath)->not->toBeFile();

    foreach ($filesystem->allFiles($source, hidden: true) as $file) {
        expect($destination.'/'.$file->getRelativePathname())->toBeFile();
    }
});

it('is a no-op when the published directory exactly matches the package build', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/zenith/build');

    expect(Artisan::call('zenith:assets', ['--force' => true]))->toBe(0);

    $manifestBefore = $filesystem->get($destination.'/manifest.json');

    app()->instance(Filesystem::class, new class extends Filesystem
    {
        public function copyDirectory($directory, $destination, $options = null): bool
        {
            throw new RuntimeException('Exact publications should not be staged.');
        }
    });

    expect(Artisan::call('zenith:assets'))->toBe(0)
        ->and($filesystem->get($destination.'/manifest.json'))->toBe($manifestBefore);
});

it('refreshes when the published directory contains files absent from the package build', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/zenith/build');
    $metadataPath = $destination.'/.platform-metadata';
    $extraHashedAsset = $destination.'/assets/app-previous-generation.js';
    $extraRootFile = $destination.'/extra-root.txt';

    expect(Artisan::call('zenith:assets', ['--force' => true]))->toBe(0);

    $filesystem->put($metadataPath, 'consumer metadata');
    $filesystem->put($extraHashedAsset, 'previous generation asset');
    $filesystem->put($extraRootFile, 'not part of the package build');

    expect(Artisan::call('zenith:assets'))->toBe(0)
        ->and($metadataPath)->not->toBeFile()
        ->and($extraHashedAsset)->not->toBeFile()
        ->and($extraRootFile)->not->toBeFile()
        ->and($destination.'/manifest.json')->toBeFile();

    $source = dirname(__DIR__, 2).'/dist/build';
    $sourcePaths = array_map(
        static fn (SplFileInfo $file): string => str_replace('\\', '/', $file->getRelativePathname()),
        $filesystem->allFiles($source, hidden: true),
    );
    $destinationPaths = array_map(
        static fn (SplFileInfo $file): string => str_replace('\\', '/', $file->getRelativePathname()),
        $filesystem->allFiles($destination, hidden: true),
    );

    sort($sourcePaths);
    sort($destinationPaths);

    expect($destinationPaths)->toBe($sourcePaths);
});

it('replaces the whole published directory when refreshing a stale publication', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/zenith/build');
    $metadataPath = $destination.'/.platform-metadata';
    $staleAsset = $destination.'/assets/app-older.js';
    $extraRootFile = $destination.'/extra-root.txt';

    $filesystem->ensureDirectoryExists($destination.'/assets');
    $filesystem->put($destination.'/manifest.json', json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-older.js',
            'css' => ['assets/app-older.css'],
            'assets' => ['assets/logo-older.svg'],
        ],
    ], JSON_THROW_ON_ERROR));
    $filesystem->put($staleAsset, 'older');
    $filesystem->put($destination.'/assets/app-older.css', 'older');
    $filesystem->put($destination.'/assets/logo-older.svg', 'older');
    $filesystem->put($metadataPath, 'consumer metadata');
    $filesystem->put($extraRootFile, 'not part of the package build');

    $sourceManifest = json_decode(
        $filesystem->get(dirname(__DIR__, 2).'/dist/build/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(Artisan::call('zenith:assets'))->toBe(0)
        ->and(json_decode($filesystem->get($destination.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe($sourceManifest)
        ->and($staleAsset)->not->toBeFile()
        ->and($metadataPath)->not->toBeFile()
        ->and($extraRootFile)->not->toBeFile()
        ->and($destination.'/assets/app-older.css')->not->toBeFile()
        ->and($destination.'/assets/logo-older.svg')->not->toBeFile();
});

it('restores the previous publication when replacing an existing destination fails', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/zenith/build');
    $manifestPath = $destination.'/manifest.json';
    $oldManifest = json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-old.js',
            'css' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    $filesystem->ensureDirectoryExists($destination.'/assets');
    $filesystem->put($manifestPath, $oldManifest);
    $filesystem->put($destination.'/assets/app-old.js', 'old asset');

    app()->instance(Filesystem::class, new class($destination) extends Filesystem
    {
        public function __construct(private readonly string $destination) {}

        public function moveDirectory($from, $to, $overwrite = false): bool
        {
            $normalizedTo = str_replace('\\', '/', (string) $to);
            $normalizedDestination = str_replace('\\', '/', $this->destination);

            if ($normalizedTo === $normalizedDestination && str_contains((string) $from, '.tmp')) {
                return false;
            }

            return parent::moveDirectory($from, $to, $overwrite);
        }
    });

    expect(fn (): int => Artisan::call('zenith:assets', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'Unable to publish')
        ->and($manifestPath)->toBeFile()
        ->and((new Filesystem)->get($manifestPath))->toBe($oldManifest)
        ->and($destination.'/assets/app-old.js')->toBeFile()
        ->and((new Filesystem)->glob(dirname($destination).'/.build-*.tmp'))->toBe([])
        ->and((new Filesystem)->glob(dirname($destination).'/.build-*.bak'))->toBe([]);
});

it('does not run production prerequisite warnings', function (): void {
    config()->set('queue.default', 'sync');

    expect(Artisan::call('zenith:assets', ['--force' => true]))->toBe(0);

    $output = Artisan::output();

    expect($output)
        ->not()->toContain('Bulk operations require an asynchronous queue connection')
        ->not()->toContain('Schedule `horizon:snapshot` every five minutes');
});
