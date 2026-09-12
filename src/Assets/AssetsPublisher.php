<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Assets;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;

final class AssetsPublisher
{
    private const int ABANDONED_STAGING_AFTER_SECONDS = 3600;

    private const string MANIFEST = 'manifest.json';

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function packageBuildPath(): string
    {
        return dirname(__DIR__, 2).'/dist/build';
    }

    public function publish(string $destination, bool $force, ?string $source = null): void
    {
        $source ??= $this->packageBuildPath();

        $this->filesystem->ensureDirectoryExists(dirname($destination));
        $this->removeAbandonedStagingDirectories($destination);

        if (! $force && $this->hasExactPublishedAssets($source, $destination)) {
            return;
        }

        $stagingDirectory = dirname($destination).'/.'.basename($destination).'-'.Str::random(20).'.tmp';

        try {
            if (! $this->filesystem->copyDirectory($source, $stagingDirectory)) {
                throw new RuntimeException('Unable to stage Zenith assets.');
            }

            if (! $this->hasCompletePublishedAssets($stagingDirectory)) {
                throw new RuntimeException('Unable to stage a complete Zenith asset build.');
            }

            $this->replacePublishedDirectory($stagingDirectory, $destination);
        } finally {
            if ($this->filesystem->isDirectory($stagingDirectory)) {
                $this->filesystem->deleteDirectory($stagingDirectory);
            }
        }
    }

    private function hasExactPublishedAssets(string $source, string $destination): bool
    {
        if (! $this->hasCompletePublishedAssets($destination)) {
            return false;
        }

        $sourceFiles = $this->relativeFileMap($source);
        $destinationFiles = $this->relativeFileMap($destination);

        if (array_keys($sourceFiles) !== array_keys($destinationFiles)) {
            return false;
        }

        foreach ($sourceFiles as $relativePath => $sourcePath) {
            if (! $this->publishedFilesMatch($sourcePath, $destinationFiles[$relativePath])) {
                return false;
            }
        }

        return true;
    }

    private function hasCompletePublishedAssets(string $destination): bool
    {
        if (! $this->filesystem->isDirectory($destination)) {
            return false;
        }

        return $this->manifestAssetPaths($destination) !== null;
    }

    private function publishedFilesMatch(string $source, string $destination): bool
    {
        return $this->filesystem->isFile($destination)
            && $this->filesystem->hash($source, 'sha256') === $this->filesystem->hash($destination, 'sha256');
    }

    /**
     * @return array<string, string>
     */
    private function relativeFileMap(string $directory): array
    {
        $map = [];

        /** @var list<SplFileInfo> $files */
        $files = $this->filesystem->allFiles($directory, hidden: true);

        foreach ($files as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $map[$relativePath] = $file->getPathname();
        }

        ksort($map);

        return $map;
    }

    /** @return list<string>|null */
    private function manifestAssetPaths(string $destination): ?array
    {
        $manifestPath = $destination.'/'.self::MANIFEST;

        if (! $this->filesystem->isFile($manifestPath)) {
            return null;
        }

        try {
            $manifest = json_decode($this->filesystem->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($manifest) || array_is_list($manifest) || ! array_key_exists('resources/js/app.tsx', $manifest)) {
            return null;
        }

        $assetPaths = [];

        foreach ($manifest as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)) {
                return null;
            }

            $file = $this->publishedAssetPath($destination, $entry['file'] ?? null);

            if ($file === null) {
                return null;
            }

            $assetPaths[] = $file;

            foreach (['css', 'assets'] as $collection) {
                $paths = $entry[$collection] ?? [];

                if (! is_array($paths)) {
                    return null;
                }

                foreach ($paths as $path) {
                    $publishedPath = $this->publishedAssetPath($destination, $path);

                    if ($publishedPath === null) {
                        return null;
                    }

                    $assetPaths[] = $publishedPath;
                }
            }

            foreach (['imports', 'dynamicImports'] as $collection) {
                $imports = $entry[$collection] ?? [];

                if (! is_array($imports)) {
                    return null;
                }

                foreach ($imports as $import) {
                    if (! is_string($import) || ! array_key_exists($import, $manifest)) {
                        return null;
                    }
                }
            }
        }

        return array_values(array_unique($assetPaths));
    }

    private function publishedAssetPath(string $destination, mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $relativePath = $this->normalizeRelativeAssetPath($path);

        if ($relativePath === null) {
            return null;
        }

        return $this->filesystem->isFile($destination.'/'.$relativePath) ? $relativePath : null;
    }

    private function normalizeRelativeAssetPath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim($path));

        if ($normalized === '' || Str::startsWith($normalized, ['/']) || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                return null;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Replace the package-owned destination with a validated staged build.
     *
     * Uses the safest cross-platform directory moves Laravel Filesystem exposes
     * (rename under the hood). This is not a guaranteed gap-free exchange: the
     * destination path can be briefly absent between moving the previous tree
     * aside and moving the staged tree into place. If the staged move fails, the
     * previous tree is restored when possible.
     */
    private function replacePublishedDirectory(string $stagingDirectory, string $destination): void
    {
        if (! $this->filesystem->isDirectory($destination)) {
            if (! $this->filesystem->moveDirectory($stagingDirectory, $destination)) {
                throw new RuntimeException("Unable to publish {$destination}.");
            }

            return;
        }

        $backupDirectory = dirname($destination).'/.'.basename($destination).'-'.Str::random(20).'.bak';

        if (! $this->filesystem->moveDirectory($destination, $backupDirectory)) {
            throw new RuntimeException("Unable to publish {$destination}.");
        }

        if (! $this->filesystem->moveDirectory($stagingDirectory, $destination)) {
            if (! $this->filesystem->moveDirectory($backupDirectory, $destination)) {
                throw new RuntimeException(
                    "Unable to publish {$destination} and restore the previous build from {$backupDirectory}.",
                );
            }

            throw new RuntimeException("Unable to publish {$destination}.");
        }

        $this->filesystem->deleteDirectory($backupDirectory);
    }

    private function removeAbandonedStagingDirectories(string $destination): void
    {
        $parent = dirname($destination);
        $base = basename($destination);
        $patterns = [
            $parent.'/.'.$base.'-*.tmp',
            $parent.'/.'.$base.'-*.bak',
        ];
        $abandonedBefore = time() - self::ABANDONED_STAGING_AFTER_SECONDS;

        foreach ($patterns as $pattern) {
            $stagingDirectories = $this->filesystem->glob($pattern) ?: [];

            foreach ($stagingDirectories as $stagingDirectory) {
                if (
                    ! is_string($stagingDirectory)
                    || ! $this->filesystem->isDirectory($stagingDirectory)
                    || $this->filesystem->lastModified($stagingDirectory) > $abandonedBefore
                ) {
                    continue;
                }

                $this->filesystem->deleteDirectory($stagingDirectory);
            }
        }
    }
}
