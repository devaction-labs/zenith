<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchCapability;
use DevactionLabs\HorizonNewDawn\Support\ComposerAssetHook;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

$installComposerJsonBackup = new class
{
    public ?string $contents = null;
};

beforeEach(function () use ($installComposerJsonBackup): void {
    $filesystem = app(Filesystem::class);
    $composerJson = base_path('composer.json');

    $installComposerJsonBackup->contents = $filesystem->exists($composerJson)
        ? $filesystem->get($composerJson)
        : null;
});

afterEach(function () use ($installComposerJsonBackup): void {
    $filesystem = app(Filesystem::class);
    $composerJson = base_path('composer.json');

    if (is_string($installComposerJsonBackup->contents)) {
        $filesystem->put($composerJson, $installComposerJsonBackup->contents);
    } elseif ($filesystem->exists($composerJson)) {
        $filesystem->delete($composerJson);
    }

    $installComposerJsonBackup->contents = null;

    $filesystem->delete(config_path('horizon-new-dawn.php'));
    $filesystem->deleteDirectory(public_path('vendor/horizon-new-dawn'));
    $filesystem->deleteDirectory(dirname(public_path()).'/horizon-new-dawn-outside-test');
});

it('publishes the package configuration and compiled assets', function (): void {
    $command = artisan('horizon-new-dawn:install', ['--force' => true]);

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('The install command did not return a pending command.');
    }

    $command
        ->expectsOutputToContain('Horizon New Dawn is ready')
        ->assertSuccessful()
        ->execute();

    $filesystem = app(Filesystem::class);
    $source = dirname(__DIR__, 2).'/dist/build';
    $destination = public_path('vendor/horizon-new-dawn/build');

    expect(config_path('horizon-new-dawn.php'))->toBeFile()
        ->and($destination.'/manifest.json')->toBeFile();

    foreach ($filesystem->allFiles($source) as $file) {
        expect($destination.'/'.$file->getRelativePathname())->toBeFile();
    }
});

it('warns when Redis Cluster connections are configured', function (): void {
    config()->set('database.redis.clusters', [
        'default' => [
            [
                'host' => '127.0.0.1',
                'port' => 6379,
            ],
        ],
    ]);

    $command = artisan('horizon-new-dawn:install', ['--force' => true]);

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('The install command did not return a pending command.');
    }

    $command
        ->expectsOutputToContain('Redis Cluster detected')
        ->assertSuccessful()
        ->execute();
});

it('installs when cached configuration predates the package', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/horizon-new-dawn/build');

    $filesystem->delete(config_path('horizon-new-dawn.php'));
    $filesystem->deleteDirectory(public_path('vendor/horizon-new-dawn'));
    config()->set('horizon-new-dawn', []);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and(config_path('horizon-new-dawn.php'))->toBeFile()
        ->and($destination.'/manifest.json')->toBeFile()
        ->and($destination.'/favicon.svg')->not->toBeFile();
});

it('ignores a stale assets_path configuration when publishing assets', function (): void {
    $filesystem = app(Filesystem::class);
    $publishedConfig = config_path('horizon-new-dawn.php');
    $outsideDirectory = dirname(public_path()).'/horizon-new-dawn-outside-test';
    $outsideSentinel = $outsideDirectory.'/keep.txt';
    $destination = public_path('vendor/horizon-new-dawn/build');
    $consumerConfig = "<?php\n\nreturn ['assets_path' => '../horizon-new-dawn-outside-test', 'consumer' => true];\n";

    $filesystem->ensureDirectoryExists(dirname($publishedConfig));
    $filesystem->put($publishedConfig, $consumerConfig);
    $filesystem->ensureDirectoryExists($outsideDirectory);
    $filesystem->put($outsideSentinel, 'keep');
    config()->set('horizon-new-dawn.assets_path', '../horizon-new-dawn-outside-test');

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0)
        ->and($filesystem->get($publishedConfig))->toBe($consumerConfig)
        ->and($filesystem->get($outsideSentinel))->toBe('keep')
        ->and($destination.'/manifest.json')->toBeFile()
        ->and($outsideDirectory.'/manifest.json')->not->toBeFile();
});

it('preserves published consumer configuration when force refreshing assets', function (): void {
    $filesystem = app(Filesystem::class);
    $publishedConfig = config_path('horizon-new-dawn.php');
    $destination = public_path('vendor/horizon-new-dawn/build');
    $staleAssetsPath = 'vendor/horizon-new-dawn-install-test/custom-build';
    $consumerConfig = <<<PHP
<?php

declare(strict_types=1);

return [
    'assets_path' => '{$staleAssetsPath}',
    'poll_interval' => 1234,
];
PHP;

    $filesystem->ensureDirectoryExists(dirname($publishedConfig));
    $filesystem->put($publishedConfig, $consumerConfig);
    config()->set('horizon-new-dawn.assets_path', $staleAssetsPath);

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0)
        ->and($filesystem->get($publishedConfig))->toBe($consumerConfig)
        ->and($destination.'/manifest.json')->toBeFile()
        ->and(public_path($staleAssetsPath.'/manifest.json'))->not->toBeFile();
});

it('replaces the whole published directory and removes prior package-owned files', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/horizon-new-dawn/build');
    $manifestPath = $destination.'/manifest.json';
    $previousAsset = $destination.'/assets/app-previous-content-hash.js';
    $supersededAsset = $destination.'/assets/app-superseded-content-hash.js';
    $previousGeneration = [
        'assets/app-previous-content-hash.js',
        'assets/app-previous-content-hash.css',
        'assets/logo-previous-content-hash.svg',
        'assets/chunk-previous-content-hash.js',
        'assets/dynamic-previous-content-hash.js',
    ];
    $oldManifest = json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-previous-content-hash.js',
            'css' => ['assets/app-previous-content-hash.css'],
            'assets' => ['assets/logo-previous-content-hash.svg'],
            'imports' => ['resources/js/chunk.ts'],
            'dynamicImports' => ['resources/js/dynamic.ts'],
        ],
        'resources/js/chunk.ts' => [
            'file' => 'assets/chunk-previous-content-hash.js',
        ],
        'resources/js/dynamic.ts' => [
            'file' => 'assets/dynamic-previous-content-hash.js',
        ],
    ], JSON_THROW_ON_ERROR);

    $filesystem->ensureDirectoryExists(dirname($previousAsset));

    foreach ($previousGeneration as $asset) {
        $filesystem->put($destination.'/'.$asset, 'previous asset');
    }

    $filesystem->put($supersededAsset, 'superseded asset');
    $filesystem->put($destination.'/.platform-metadata', 'consumer metadata');
    $filesystem->ensureDirectoryExists($destination);
    $filesystem->put($manifestPath, $oldManifest);

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0)
        ->and($manifestPath)->toBeFile()
        ->and($filesystem->get($manifestPath))->not->toBe($oldManifest)
        ->and($previousAsset)->not->toBeFile()
        ->and($supersededAsset)->not->toBeFile()
        ->and($destination.'/.platform-metadata')->not->toBeFile();

    foreach ($previousGeneration as $asset) {
        expect($destination.'/'.$asset)->not->toBeFile();
    }
});

it('removes untracked package-directory files when force refreshing an incomplete publication', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/horizon-new-dawn/build');
    $previousAsset = $destination.'/assets/app-previous.js';
    $untrackedAsset = $destination.'/assets/app-untracked.js';

    $filesystem->ensureDirectoryExists(dirname($previousAsset));
    $filesystem->put($previousAsset, 'previous asset');
    $filesystem->put($untrackedAsset, 'untracked asset');
    $filesystem->ensureDirectoryExists($destination);
    $filesystem->put($destination.'/manifest.json', json_encode([
        'resources/js/app.tsx' => [
            'file' => 'assets/app-previous.js',
            'imports' => ['missing-entry'],
        ],
    ], JSON_THROW_ON_ERROR));

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0)
        ->and($destination.'/manifest.json')->toBeFile()
        ->and($previousAsset)->not->toBeFile()
        ->and($untrackedAsset)->not->toBeFile();
});

it('restores the previous publication when replacing an existing destination fails', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/horizon-new-dawn/build');
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
    $abandonedStagingDirectory = dirname($destination).'/.build-abandoned.tmp';
    $filesystem->ensureDirectoryExists($abandonedStagingDirectory);
    touch($abandonedStagingDirectory, time() - 7200);

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

    expect(fn (): int => Artisan::call('horizon-new-dawn:install', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'Unable to publish')
        ->and($manifestPath)->toBeFile()
        ->and((new Filesystem)->get($manifestPath))->toBe($oldManifest)
        ->and($destination.'/assets/app-old.js')->toBeFile()
        ->and((new Filesystem)->glob(dirname($destination).'/.build-*.tmp'))->toBe([])
        ->and((new Filesystem)->glob(dirname($destination).'/.build-*.bak'))->toBe([]);
});

it('repairs an empty published assets directory without force', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    $filesystem->ensureDirectoryExists($destination);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and($destination.'/manifest.json')->toBeFile()
        ->and($destination.'/favicon.svg')->not->toBeFile();
});

it('repairs a top-level list manifest without force', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    publishBrokenPublication($filesystem, $destination, []);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and(installedManifest($destination))->toHaveKey('resources/js/app.tsx');
});

it('repairs a publication with a missing referenced chunk without force', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    publishBrokenPublication($filesystem, $destination, [
        'resources/js/app.tsx' => [
            'file' => 'assets/app-missing.js',
        ],
    ], [
        'missing' => ['assets/app-missing.js'],
    ]);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and($destination.'/assets/app-missing.js')->not->toBeFile()
        ->and(installedManifest($destination)['resources/js/app.tsx']['file'] ?? null)->not->toBe('assets/app-missing.js');
});

it('repairs a malformed published manifest without force', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    publishCompletePublication($filesystem, $destination);
    $filesystem->put($destination.'/manifest.json', '{invalid');

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and($destination.'/manifest.json')->toBeFile()
        ->and(installedManifest($destination))->toHaveKey('resources/js/app.tsx');
});

it('repairs a published manifest that is missing the entrypoint without force', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    publishManifestFixture($filesystem, $destination, [
        'resources/js/other.tsx' => [
            'file' => 'assets/app-other.js',
            'css' => [],
            'assets' => [],
        ],
    ], ['assets/app-other.js']);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and(installedManifest($destination))->toHaveKey('resources/js/app.tsx');
});

it('treats a complete publication without a favicon as current', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    $filesystem->deleteDirectory($destination);
    publishCompletePublication($filesystem, $destination);

    expect($destination.'/favicon.svg')->not->toBeFile()
        ->and(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and($destination.'/favicon.svg')->not->toBeFile()
        ->and(installedManifest($destination))->toHaveKey('resources/js/app.tsx');
});

it('repairs a publication with an invalid file path without force', function (string $path): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    publishBrokenPublication($filesystem, $destination, [
        'resources/js/app.tsx' => [
            'file' => $path,
        ],
    ]);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and(installedManifest($destination)['resources/js/app.tsx']['file'] ?? null)->not->toBe($path);
})->with([
    'empty file' => [''],
    'absolute file' => ['/escape.js'],
    'traversal file' => ['../escape.js'],
]);

it('repairs a publication with an invalid asset collection path without force', function (string $collection, string $path): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    publishBrokenPublication($filesystem, $destination, [
        'resources/js/app.tsx' => [
            $collection => [$path],
        ],
    ]);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and(installedManifest($destination)['resources/js/app.tsx'][$collection] ?? [])->not->toContain($path);
})->with([
    'missing css' => ['css', 'assets/missing.css'],
    'unsafe css' => ['css', '../escape.css'],
    'missing asset' => ['assets', 'assets/missing.svg'],
    'unsafe asset' => ['assets', '/escape.svg'],
]);

it('repairs a publication with a dangling import without force', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    publishBrokenPublication($filesystem, $destination, [
        'resources/js/app.tsx' => [
            'imports' => ['missing-chunk'],
        ],
    ]);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and(installedManifest($destination)['resources/js/app.tsx']['imports'] ?? [])->not->toContain('missing-chunk');
});

it('repairs a publication with a dangling dynamic import without force', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);

    publishBrokenPublication($filesystem, $destination, [
        'resources/js/app.tsx' => [
            'dynamicImports' => ['missing-dynamic-chunk'],
        ],
    ]);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and(installedManifest($destination)['resources/js/app.tsx']['dynamicImports'] ?? [])->not->toContain('missing-dynamic-chunk');
});

it('refreshes a complete stale publication without force', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);
    $manifest = [
        'resources/js/app.tsx' => [
            'file' => 'assets/app-older.js',
            'css' => ['assets/app-older.css'],
            'assets' => ['assets/logo-older.svg'],
        ],
    ];

    publishManifestFixture($filesystem, $destination, $manifest, [
        'assets/app-older.js',
        'assets/app-older.css',
        'assets/logo-older.svg',
    ]);

    $sourceManifest = json_decode(
        $filesystem->get(dirname(__DIR__, 2).'/dist/build/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and(installedManifest($destination))->toBe($sourceManifest)
        ->and($destination.'/assets/app-older.js')->not->toBeFile()
        ->and($destination.'/assets/app-older.css')->not->toBeFile()
        ->and($destination.'/assets/logo-older.svg')->not->toBeFile();
});

it('refreshes when only the published manifest differs from the package build', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);
    $manifestPath = $destination.'/manifest.json';

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0);

    $staleManifest = installedManifest($destination);
    $staleManifest['resources/js/app.tsx']['stale'] = true;
    $filesystem->put($manifestPath, json_encode($staleManifest, JSON_THROW_ON_ERROR));

    $sourceManifest = json_decode(
        $filesystem->get(dirname(__DIR__, 2).'/dist/build/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and(installedManifest($destination))->toBe($sourceManifest);
});

it('refreshes when the published directory contains files absent from the package build', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/horizon-new-dawn/build');
    $metadataPath = $destination.'/.platform-metadata';
    $extraAsset = $destination.'/assets/app-extra.js';

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0);

    $filesystem->put($metadataPath, 'consumer metadata');
    $filesystem->put($extraAsset, 'extra asset');

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and($metadataPath)->not->toBeFile()
        ->and($extraAsset)->not->toBeFile()
        ->and($destination.'/manifest.json')->toBeFile();
});

it('is a no-op when the published directory exactly matches the package build', function (): void {
    $filesystem = app(Filesystem::class);
    $destination = public_path('vendor/horizon-new-dawn/build');

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0);

    $manifestBefore = $filesystem->get($destination.'/manifest.json');

    app()->instance(Filesystem::class, new class extends Filesystem
    {
        public function copyDirectory($directory, $destination, $options = null): bool
        {
            throw new RuntimeException('Exact publications should not be staged.');
        }
    });

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and($filesystem->get($destination.'/manifest.json'))->toBe($manifestBefore);
});

it('removes abandoned staging directories while preserving fresh sibling publishes', function (): void {
    $filesystem = app(Filesystem::class);
    $assetsPath = 'vendor/horizon-new-dawn/build';
    $destination = public_path($assetsPath);
    $abandonedStagingDirectory = dirname($destination).'/.build-abandoned.tmp';
    $freshStagingDirectory = dirname($destination).'/.build-active.tmp';

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0);

    $filesystem->ensureDirectoryExists($abandonedStagingDirectory);
    $filesystem->ensureDirectoryExists($freshStagingDirectory);
    touch($abandonedStagingDirectory, time() - 7200);

    expect(Artisan::call('horizon-new-dawn:install'))->toBe(0)
        ->and($abandonedStagingDirectory)->not->toBeDirectory()
        ->and($freshStagingDirectory)->toBeDirectory();
});

it('warns when production queue and metrics prerequisites are missing', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    Schema::dropIfExists('job_batches');
    Schema::dropIfExists(DatabaseBatchCapability::METADATA_TABLE);
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
    });

    try {
        expect(Artisan::call('horizon-new-dawn:install', ['--no-composer-hook' => true]))->toBe(0)
            ->and(Artisan::output())
            ->toContain('Bulk operations require an asynchronous queue connection')
            ->toContain('Schedule `horizon:snapshot` every five minutes')
            ->toContain('Run `php artisan migrate` to enable batch queue and connection filters');
    } finally {
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists(DatabaseBatchCapability::METADATA_TABLE);
    }
});

it('appends the Composer asset refresh hook during a normal install', function (): void {
    $filesystem = app(Filesystem::class);
    $composerJson = base_path('composer.json');

    $filesystem->put($composerJson, json_encode([
        'name' => 'laravel/laravel',
        'scripts' => [
            'post-autoload-dump' => [
                '@php artisan package:discover --ansi',
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Added the Horizon New Dawn asset refresh Composer hook');

    $composer = json_decode($filesystem->get($composerJson), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['post-autoload-dump'])->toBe([
        '@php artisan package:discover --ansi',
        ComposerAssetHook::SCRIPT,
    ]);
});

it('does not rewrite composer.json when the asset hook already exists', function (): void {
    $filesystem = app(Filesystem::class);
    $composerJson = base_path('composer.json');
    $original = json_encode([
        'name' => 'laravel/laravel',
        'scripts' => [
            'post-autoload-dump' => [
                '@php artisan package:discover --ansi',
                ComposerAssetHook::SCRIPT,
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

    $filesystem->put($composerJson, $original);

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0);
    expect(Artisan::output())->not()->toContain('Added the Horizon New Dawn asset refresh Composer hook');
    expect($filesystem->get($composerJson))->toBe($original);
});

it('skips composer.json mutation when --no-composer-hook is provided', function (): void {
    $filesystem = app(Filesystem::class);
    $composerJson = base_path('composer.json');
    $original = json_encode([
        'name' => 'laravel/laravel',
        'scripts' => [
            'post-autoload-dump' => [
                '@php artisan package:discover --ansi',
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

    $filesystem->put($composerJson, $original);

    expect(Artisan::call('horizon-new-dawn:install', [
        '--force' => true,
        '--no-composer-hook' => true,
    ]))->toBe(0);
    expect($filesystem->get($composerJson))->toBe($original);
    expect(Artisan::output())->not()->toContain('Added the Horizon New Dawn asset refresh Composer hook');
});

it('warns and continues when composer.json cannot be updated', function (): void {
    $filesystem = app(Filesystem::class);
    $composerJson = base_path('composer.json');
    $filesystem->put($composerJson, '{invalid');

    expect(Artisan::call('horizon-new-dawn:install', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())
        ->toContain('Could not update composer.json with the asset refresh hook')
        ->toContain('Horizon New Dawn is ready')
        ->and(config_path('horizon-new-dawn.php'))->toBeFile()
        ->and(public_path('vendor/horizon-new-dawn/build/manifest.json'))->toBeFile()
        ->and($filesystem->get($composerJson))->toBe('{invalid');
});

/**
 * @param  array<int|string, mixed>  $overrideManifest
 * @param  array{missing?: list<string>, override?: array<string, string>}  $mutations
 */
function publishBrokenPublication(Filesystem $filesystem, string $destination, array $overrideManifest, array $mutations = []): void
{
    $baseManifest = completePublicationManifest();
    $manifest = array_is_list($overrideManifest)
        ? $overrideManifest
        : array_replace_recursive($baseManifest, $overrideManifest);

    $files = completePublicationFiles();

    foreach ($mutations['missing'] ?? [] as $missingPath) {
        unset($files[$missingPath]);
    }

    foreach ($mutations['override'] ?? [] as $path => $contents) {
        $files[$path] = $contents;
    }

    publishManifestFixture($filesystem, $destination, $manifest, array_keys($files));

    foreach ($files as $path => $contents) {
        $filesystem->put($destination.'/'.$path, $contents);
    }
}

function publishCompletePublication(Filesystem $filesystem, string $destination): void
{
    publishManifestFixture(
        $filesystem,
        $destination,
        completePublicationManifest(),
        array_keys(completePublicationFiles()),
    );

    foreach (completePublicationFiles() as $path => $contents) {
        $filesystem->put($destination.'/'.$path, $contents);
    }
}

/**
 * @param  array<string, array<string, list<string>|string>>|list<mixed>  $manifest
 * @param  list<string>  $files
 */
function publishManifestFixture(
    Filesystem $filesystem,
    string $destination,
    array $manifest,
    array $files = [],
): void {
    $filesystem->ensureDirectoryExists($destination);
    $filesystem->put($destination.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    foreach ($files as $file) {
        $filesystem->ensureDirectoryExists(dirname($destination.'/'.$file));

        if (! $filesystem->exists($destination.'/'.$file)) {
            $filesystem->put($destination.'/'.$file, 'fixture:'.$file);
        }
    }
}

/** @return array<string, array{file: string, css: list<string>, assets: list<string>, imports?: list<string>, dynamicImports?: list<string>}> */
function completePublicationManifest(): array
{
    return [
        'resources/js/app.tsx' => [
            'file' => 'assets/app.js',
            'css' => ['assets/app.css'],
            'assets' => ['assets/logo.svg'],
            'imports' => ['resources/js/chunk.ts'],
            'dynamicImports' => ['resources/js/dynamic.ts'],
        ],
        'resources/js/chunk.ts' => [
            'file' => 'assets/chunk.js',
            'css' => [],
            'assets' => [],
        ],
        'resources/js/dynamic.ts' => [
            'file' => 'assets/dynamic.js',
            'css' => [],
            'assets' => [],
        ],
    ];
}

/** @return array<string, string> */
function completePublicationFiles(): array
{
    return [
        'assets/app.js' => 'fixture:assets/app.js',
        'assets/app.css' => 'fixture:assets/app.css',
        'assets/logo.svg' => 'fixture:assets/logo.svg',
        'assets/chunk.js' => 'fixture:assets/chunk.js',
        'assets/dynamic.js' => 'fixture:assets/dynamic.js',
    ];
}

/** @return array<string, array<string, list<string>|string>> */
function installedManifest(string $buildDirectory): array
{
    $manifest = json_decode(
        app(Filesystem::class)->get($buildDirectory.'/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (! is_array($manifest)) {
        throw new RuntimeException('The installed Vite manifest is invalid.');
    }

    return $manifest;
}
