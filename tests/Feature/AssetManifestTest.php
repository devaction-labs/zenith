<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Assets\AssetManifest;
use DevactionLabs\Zenith\Assets\AssetPath;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use function Pest\Laravel\get;

$isolatedPublicPath = null;

beforeEach(function () use (&$isolatedPublicPath): void {
    $publicPath = sys_get_temp_dir().'/zenith-asset-test-'.uniqid('', true);
    $filesystem = app(Filesystem::class);

    $filesystem->ensureDirectoryExists($publicPath);
    app()->usePublicPath($publicPath);

    $isolatedPublicPath = $publicPath;
});

afterEach(function () use (&$isolatedPublicPath): void {
    $filesystem = app(Filesystem::class);

    if (is_string($isolatedPublicPath)) {
        $filesystem->deleteDirectory($isolatedPublicPath);
        $filesystem->deleteDirectory(dirname($isolatedPublicPath).'/zenith-assets-outside');
    }

    $isolatedPublicPath = null;
});

it('resolves hashed entry assets from the published Vite manifest', function (): void {
    $filesystem = app(Filesystem::class);
    $buildDirectory = public_path('vendor/zenith/build');

    $filesystem->ensureDirectoryExists($buildDirectory);
    $filesystem->put($buildDirectory.'/manifest.json', json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-abc123.js',
            'css' => ['assets/app-def456.css'],
            'isEntry' => true,
            'src' => 'resources/js/app.tsx',
        ],
        'resources/images/favicon.svg' => [
            'file' => 'assets/favicon-xyz789.svg',
            'src' => 'resources/images/favicon.svg',
        ],
    ], JSON_THROW_ON_ERROR));

    $tags = (string) app(AssetManifest::class)->tags();

    expect($tags)
        ->toContain(url('/vendor/zenith/build/assets/app-abc123.js'))
        ->toContain(url('/vendor/zenith/build/assets/app-def456.css'))
        ->toContain('type="module"')
        ->toContain('rel="stylesheet"');
    expect($tags)->not->toContain('@vite/client');
});

it('resolves the hashed favicon URL from the package-scoped Vite manifest entry', function (): void {
    $filesystem = app(Filesystem::class);
    $buildDirectory = public_path('vendor/zenith/build');

    $filesystem->ensureDirectoryExists($buildDirectory.'/assets');
    $filesystem->put($buildDirectory.'/manifest.json', json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-abc123.js',
            'css' => [],
            'isEntry' => true,
            'src' => 'resources/js/app.tsx',
        ],
        'resources/images/favicon.svg' => [
            'file' => 'assets/favicon-xyz789.svg',
            'src' => 'resources/images/favicon.svg',
        ],
    ], JSON_THROW_ON_ERROR));
    $filesystem->put($buildDirectory.'/assets/favicon-xyz789.svg', '<svg />');

    expect(app(AssetManifest::class)->favicon())
        ->toBe(url('/vendor/zenith/build/assets/favicon-xyz789.svg'));
});

it('explains how to repair a missing published favicon manifest entry', function (): void {
    $filesystem = app(Filesystem::class);
    $buildDirectory = public_path('vendor/zenith/build');

    $filesystem->ensureDirectoryExists($buildDirectory);
    $filesystem->put($buildDirectory.'/manifest.json', json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-abc123.js',
            'css' => [],
            'isEntry' => true,
            'src' => 'resources/js/app.tsx',
        ],
    ], JSON_THROW_ON_ERROR));

    expect(fn (): string => app(AssetManifest::class)->favicon())
        ->toThrow(RuntimeException::class, 'php artisan zenith:install');
});

it('uses the package asset path when cached configuration has no package keys', function (): void {
    config()->set('zenith', []);

    expect(app(AssetPath::class)->relative())->toBe('vendor/zenith/build')
        ->and(app(AssetPath::class)->absolute())->toBe(resolvedPackageAssetAbsolutePath())
        ->and(app(AssetPath::class)->manifest())->toBe(
            resolvedPackageAssetAbsolutePath().DIRECTORY_SEPARATOR.'manifest.json',
        );
});

it('ignores a stale assets_path configuration value', function (): void {
    config()->set('zenith.assets_path', 'vendor/custom-horizon-assets/build');

    expect(app(AssetPath::class)->relative())->toBe('vendor/zenith/build')
        ->and(app(AssetPath::class)->absolute())->toBe(resolvedPackageAssetAbsolutePath());
});

it('explains how to repair a missing published manifest', function (): void {
    expect(fn () => app(AssetManifest::class)->tags())
        ->toThrow(RuntimeException::class, 'php artisan zenith:install');
});

it('rejects an asset path whose existing symlink escapes public', function (): void {
    $filesystem = app(Filesystem::class);
    $outsideDirectory = dirname(public_path()).'/zenith-assets-outside';
    $symlink = public_path('vendor/zenith');

    $filesystem->ensureDirectoryExists($outsideDirectory);
    $filesystem->ensureDirectoryExists(dirname($symlink));
    $filesystem->link($outsideDirectory, $symlink);

    expect(fn (): string => app(AssetPath::class)->absolute())
        ->toThrow(RuntimeException::class, 'resolve within the public directory');
});

function resolvedPackageAssetAbsolutePath(): string
{
    $publicPath = realpath(public_path());

    if (! is_string($publicPath)) {
        throw new RuntimeException('The test public path could not be resolved.');
    }

    return $publicPath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'zenith'.DIRECTORY_SEPARATOR.'build';
}

it('ships every file referenced by the production Vite manifest', function (): void {
    $buildPath = dirname(__DIR__, 2).'/dist/build';
    $manifest = json_decode(
        app(Filesystem::class)->get($buildPath.'/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (! is_array($manifest)) {
        throw new RuntimeException('The production Vite manifest is invalid.');
    }

    expect($manifest)->toHaveKey('resources/images/favicon.svg')
        ->and($buildPath.'/favicon.svg')->not->toBeFile()
        ->and($buildPath.'/FONT_LICENSES.md')->not->toBeFile()
        ->and($buildPath.'/THIRD_PARTY_LICENSES.md')->not->toBeFile();

    foreach ($manifest as $entry) {
        if (! is_array($entry)) {
            throw new RuntimeException('The production Vite manifest contains an invalid entry.');
        }

        $file = $entry['file'] ?? null;

        if (! is_string($file)) {
            throw new RuntimeException('A production Vite manifest entry has no file.');
        }

        expect($buildPath.'/'.$file)->toBeFile();

        foreach (['css', 'assets'] as $collection) {
            $paths = $entry[$collection] ?? [];

            if (! is_array($paths)) {
                throw new RuntimeException("A production Vite manifest entry has invalid {$collection}.");
            }

            foreach ($paths as $path) {
                if (! is_string($path)) {
                    throw new RuntimeException("A production Vite manifest entry has an invalid {$collection} path.");
                }

                expect($buildPath.'/'.$path)->toBeFile();
            }
        }

        foreach (['imports', 'dynamicImports'] as $collection) {
            $imports = $entry[$collection] ?? [];

            if (! is_array($imports)) {
                throw new RuntimeException("A production Vite manifest entry has invalid {$collection}.");
            }

            foreach ($imports as $import) {
                if (! is_string($import)) {
                    throw new RuntimeException("A production Vite manifest entry has an invalid {$collection} key.");
                }

                expect($manifest)->toHaveKey($import);
            }
        }
    }
});

it('ignores a consumer public/hot file and never mutates the global Vite singleton', function (): void {
    $filesystem = app(Filesystem::class);
    $buildDirectory = public_path('vendor/zenith/build');
    $globalVite = app(Vite::class);
    $originalHotFile = $globalVite->hotFile();

    $filesystem->ensureDirectoryExists($buildDirectory.'/assets');
    $filesystem->put($buildDirectory.'/manifest.json', json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-published.js',
            'css' => ['assets/app-published.css'],
            'isEntry' => true,
            'src' => 'resources/js/app.tsx',
        ],
        'resources/images/favicon.svg' => [
            'file' => 'assets/favicon-published.svg',
            'src' => 'resources/images/favicon.svg',
        ],
    ], JSON_THROW_ON_ERROR));
    $filesystem->put($buildDirectory.'/assets/app-published.js', 'published');
    $filesystem->put($buildDirectory.'/assets/app-published.css', 'published');
    $filesystem->put($buildDirectory.'/assets/favicon-published.svg', '<svg />');
    $filesystem->put(public_path('hot'), 'http://127.0.0.1:5173');

    expect($globalVite->isRunningHot())->toBeTrue()
        ->and($globalVite->hotFile())->toBe(public_path('/hot'));

    Route::get(
        '/package-vite-hot-isolation',
        fn () => Inertia::render('Test')->rootView('zenith::app'),
    );

    $content = get('/package-vite-hot-isolation')
        ->assertOk()
        ->getContent();

    if ($content === false) {
        throw new RuntimeException('The hot-isolation response content could not be read.');
    }

    expect($content)
        ->toContain('/vendor/zenith/build/assets/app-published.js')
        ->toContain('/vendor/zenith/build/assets/app-published.css')
        ->toContain('/vendor/zenith/build/assets/favicon-published.svg')
        ->toContain('data-horizon-favicon');
    expect($content)
        ->not->toContain('http://127.0.0.1:5173')
        ->and($content)->not->toContain('@vite/client')
        ->and($content)->not->toContain('resources/js/app.tsx')
        ->and($content)->not->toContain('/vendor/zenith/build/favicon.svg');
    expect($globalVite->isRunningHot())->toBeTrue()
        ->and($globalVite->hotFile())->toBe($originalHotFile)
        ->and($globalVite->hotFile())->toBe(public_path('/hot'));
});

it('propagates the host Vite CSP nonce onto package Vite tags', function (): void {
    $filesystem = app(Filesystem::class);
    $buildDirectory = public_path('vendor/zenith/build');

    $filesystem->ensureDirectoryExists($buildDirectory.'/assets');
    $filesystem->put($buildDirectory.'/manifest.json', json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-nonce.js',
            'css' => ['assets/app-nonce.css'],
            'isEntry' => true,
            'src' => 'resources/js/app.tsx',
        ],
    ], JSON_THROW_ON_ERROR));
    $filesystem->put($buildDirectory.'/assets/app-nonce.js', 'nonce');
    $filesystem->put($buildDirectory.'/assets/app-nonce.css', 'nonce');

    app(Vite::class)->useCspNonce('package-vite-csp-nonce');

    $tags = (string) app(AssetManifest::class)->tags();

    expect($tags)
        ->toContain('nonce="package-vite-csp-nonce"')
        ->toContain('type="module"')
        ->toContain('rel="stylesheet"')
        ->and(app(Vite::class)->cspNonce())->toBe('package-vite-csp-nonce');
});

it('versions requests with Laravel Vite manifestHash', function (): void {
    $filesystem = app(Filesystem::class);
    $buildDirectory = public_path('vendor/zenith/build');
    $manifestPath = $buildDirectory.'/manifest.json';

    $filesystem->ensureDirectoryExists($buildDirectory);

    $firstManifest = json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-first.js',
            'css' => [],
            'isEntry' => true,
            'src' => 'resources/js/app.tsx',
        ],
    ], JSON_THROW_ON_ERROR);
    $secondManifest = json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-second.js',
            'css' => [],
            'isEntry' => true,
            'src' => 'resources/js/app.tsx',
        ],
    ], JSON_THROW_ON_ERROR);

    $filesystem->put($manifestPath, $firstManifest);

    expect(app(AssetManifest::class)->version())->toBe(md5($firstManifest));

    $filesystem->put($manifestPath, $secondManifest);

    expect(app(AssetManifest::class)->version())->toBe(md5($secondManifest));
});
