<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Support\ComposerAssetHook;
use DevactionLabs\Zenith\Support\ComposerAssetHookResult;
use Illuminate\Filesystem\Filesystem;

it('appends the asset refresh hook after existing post-autoload-dump entries', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $filesystem->put($composerJson, json_encode([
            'name' => 'acme/app',
            'scripts' => [
                'post-autoload-dump' => [
                    '@php artisan package:discover --ansi',
                ],
                'test' => 'pest',
            ],
            'require' => [
                'php' => '^8.3',
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::Added);

        $composer = json_decode($filesystem->get($composerJson), true, flags: JSON_THROW_ON_ERROR);

        expect($composer['scripts']['post-autoload-dump'])->toBe([
            '@php artisan package:discover --ansi',
            ComposerAssetHook::SCRIPT,
        ])
            ->and($composer['scripts']['test'])->toBe('pest')
            ->and($composer['name'])->toBe('acme/app')
            ->and($composer['require'])->toBe(['php' => '^8.3']);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('creates post-autoload-dump when scripts are missing', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $filesystem->put($composerJson, json_encode([
            'name' => 'acme/app',
        ], JSON_THROW_ON_ERROR));

        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::Added);

        $composer = json_decode($filesystem->get($composerJson), true, flags: JSON_THROW_ON_ERROR);

        expect($composer['scripts']['post-autoload-dump'])->toBe([
            ComposerAssetHook::SCRIPT,
        ]);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('creates post-autoload-dump when the scripts map exists without that event', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $filesystem->put($composerJson, json_encode([
            'scripts' => [
                'test' => 'pest',
            ],
        ], JSON_THROW_ON_ERROR));

        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::Added);

        $composer = json_decode($filesystem->get($composerJson), true, flags: JSON_THROW_ON_ERROR);

        expect($composer['scripts']['post-autoload-dump'])->toBe([ComposerAssetHook::SCRIPT])
            ->and($composer['scripts']['test'])->toBe('pest');
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('promotes a string post-autoload-dump entry into a list before appending', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $filesystem->put($composerJson, json_encode([
            'scripts' => [
                'post-autoload-dump' => '@php artisan package:discover --ansi',
            ],
        ], JSON_THROW_ON_ERROR));

        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::Added);

        $composer = json_decode($filesystem->get($composerJson), true, flags: JSON_THROW_ON_ERROR);

        expect($composer['scripts']['post-autoload-dump'])->toBe([
            '@php artisan package:discover --ansi',
            ComposerAssetHook::SCRIPT,
        ]);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('is a no-op when the exact asset hook already exists', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $original = json_encode([
            'scripts' => [
                'post-autoload-dump' => [
                    '@php artisan package:discover --ansi',
                    ComposerAssetHook::SCRIPT,
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

        $filesystem->put($composerJson, $original);

        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::AlreadyPresent);
        expect($filesystem->get($composerJson))->toBe($original);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('detects a semantic asset hook without rewriting composer.json', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $original = json_encode([
            'scripts' => [
                'post-autoload-dump' => [
                    '@php artisan package:discover --ansi',
                    'php artisan zenith:assets',
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

        $filesystem->put($composerJson, $original);

        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::AlreadyPresent);
        expect($filesystem->get($composerJson))->toBe($original);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('detects a semantic asset hook inside a string post-autoload-dump entry', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $original = json_encode([
            'scripts' => [
                'post-autoload-dump' => '@php artisan zenith:assets --ansi',
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

        $filesystem->put($composerJson, $original);

        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::AlreadyPresent);
        expect($filesystem->get($composerJson))->toBe($original);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('returns missing without creating a file when composer.json is absent', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::Missing);
        expect($composerJson)->not()->toBeFile();
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('returns malformed without rewriting invalid composer.json', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $original = '{invalid';
        $filesystem->put($composerJson, $original);

        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::Malformed);
        expect($filesystem->get($composerJson))->toBe($original);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('returns malformed when post-autoload-dump is neither a string nor a list of strings', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $original = json_encode([
            'scripts' => [
                'post-autoload-dump' => ['nested' => ['@php artisan package:discover --ansi']],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

        $filesystem->put($composerJson, $original);

        expect($hook->ensure($composerJson))->toBe(ComposerAssetHookResult::Malformed);
        expect($filesystem->get($composerJson))->toBe($original);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('returns failed when composer.json cannot be read', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $filesystem->put($composerJson, json_encode([
            'scripts' => [
                'post-autoload-dump' => [
                    '@php artisan package:discover --ansi',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $unreadableFilesystem = new class extends Filesystem
        {
            public function isFile($path): bool
            {
                return true;
            }

            public function get($path, $lock = false): string
            {
                throw new RuntimeException('Unable to read composer.json.');
            }
        };

        $unreadableHook = new ComposerAssetHook($unreadableFilesystem);

        expect($unreadableHook->ensure($composerJson))->toBe(ComposerAssetHookResult::Failed);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('returns failed when composer.json cannot be written and leaves the original file unchanged', function (): void {
    [$filesystem, $composerJson, $hook, $directory] = composerAssetHookFixture();

    try {
        $original = json_encode([
            'name' => 'acme/app',
            'scripts' => [
                'post-autoload-dump' => [
                    '@php artisan package:discover --ansi',
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

        $filesystem->put($composerJson, $original);

        $failingFilesystem = new class($composerJson) extends Filesystem
        {
            public function __construct(
                private readonly string $composerJsonPath,
            ) {}

            public function put($path, $contents, $lock = false): int|bool
            {
                if (str_starts_with((string) $path, $this->composerJsonPath.'.') && str_ends_with((string) $path, '.tmp')) {
                    return false;
                }

                return parent::put($path, $contents, $lock);
            }
        };

        $failingHook = new ComposerAssetHook($failingFilesystem);

        expect($failingHook->ensure($composerJson))->toBe(ComposerAssetHookResult::Failed);
        expect($filesystem->get($composerJson))->toBe($original);
        expect(glob($composerJson.'.*.tmp') ?: [])->toBe([]);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

/**
 * @return array{0: Filesystem, 1: string, 2: ComposerAssetHook, 3: string}
 */
function composerAssetHookFixture(): array
{
    $filesystem = new Filesystem;
    $directory = sys_get_temp_dir().'/zenith-composer-hook-'.uniqid('', true);
    $filesystem->ensureDirectoryExists($directory);

    return [
        $filesystem,
        $directory.'/composer.json',
        new ComposerAssetHook($filesystem),
        $directory,
    ];
}
