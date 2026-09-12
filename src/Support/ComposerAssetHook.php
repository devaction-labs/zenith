<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class ComposerAssetHook
{
    public const string SCRIPT = '@php artisan zenith:assets --ansi';

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function ensure(string $composerJsonPath): ComposerAssetHookResult
    {
        if (! $this->filesystem->isFile($composerJsonPath)) {
            return ComposerAssetHookResult::Missing;
        }

        try {
            $contents = $this->filesystem->get($composerJsonPath);
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ComposerAssetHookResult::Malformed;
        } catch (Throwable) {
            return ComposerAssetHookResult::Failed;
        }

        if (! is_array($composer) || array_is_list($composer)) {
            return ComposerAssetHookResult::Malformed;
        }

        $scripts = $composer['scripts'] ?? [];

        if ($scripts !== [] && (! is_array($scripts) || array_is_list($scripts))) {
            return ComposerAssetHookResult::Malformed;
        }

        if ($scripts === []) {
            $scripts = [];
        }

        $postAutoloadDump = $scripts['post-autoload-dump'] ?? null;

        if ($postAutoloadDump === null) {
            $entries = [];
        } elseif (is_string($postAutoloadDump)) {
            $entries = [$postAutoloadDump];
        } elseif (is_array($postAutoloadDump) && array_is_list($postAutoloadDump)) {
            $entries = [];

            foreach ($postAutoloadDump as $entry) {
                if (! is_string($entry)) {
                    return ComposerAssetHookResult::Malformed;
                }

                $entries[] = $entry;
            }
        } else {
            return ComposerAssetHookResult::Malformed;
        }

        if ($this->containsAssetHook($entries)) {
            return ComposerAssetHookResult::AlreadyPresent;
        }

        $entries[] = self::SCRIPT;
        $scripts['post-autoload-dump'] = $entries;
        $composer['scripts'] = $scripts;

        try {
            $encoded = json_encode(
                $composer,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n";

            $this->writeAtomically($composerJsonPath, $encoded);
        } catch (Throwable) {
            return ComposerAssetHookResult::Failed;
        }

        return ComposerAssetHookResult::Added;
    }

    /** @param  list<string>  $entries */
    private function containsAssetHook(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (str_contains($entry, 'zenith:assets')) {
                return true;
            }
        }

        return false;
    }

    private function writeAtomically(string $path, string $contents): void
    {
        $temporaryPath = $path.'.'.Str::random(20).'.tmp';

        try {
            if ($this->filesystem->put($temporaryPath, $contents) === false) {
                throw new \RuntimeException("Unable to write temporary composer.json at {$temporaryPath}.");
            }

            if (! $this->filesystem->move($temporaryPath, $path)) {
                throw new \RuntimeException("Unable to update composer.json at {$path}.");
            }
        } finally {
            if ($this->filesystem->exists($temporaryPath)) {
                $this->filesystem->delete($temporaryPath);
            }
        }
    }
}
