<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Assets;

use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteException;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Support\HtmlString;
use RuntimeException;

final readonly class AssetManifest
{
    private const string ENTRY = 'resources/js/app.tsx';

    private const string FAVICON = 'resources/images/favicon.svg';

    public function __construct(
        private AssetPath $assetPath,
    ) {}

    public function tags(): HtmlString
    {
        try {
            return ($this->vite())(self::ENTRY);
        } catch (ViteException $exception) {
            throw $this->missingAssetsException($exception);
        }
    }

    public function favicon(): string
    {
        try {
            return $this->vite()->asset(self::FAVICON);
        } catch (ViteException $exception) {
            throw $this->missingAssetsException($exception);
        }
    }

    public function version(): string
    {
        $hash = $this->vite()->manifestHash();

        if (! is_string($hash) || $hash === '') {
            throw new RuntimeException(
                'Horizon New Dawn assets are not published. Run `php artisan horizon-new-dawn:install`.',
            );
        }

        return $hash;
    }

    /**
     * Package-scoped Vite renderer.
     *
     * Uses a dedicated instance so the consuming application's public/hot file
     * and global Vite configuration are never consulted or mutated.
     */
    private function vite(): Vite
    {
        $vite = (new Vite)
            ->useBuildDirectory($this->assetPath->relative())
            ->useHotFile($this->packageHotFile());

        $nonce = app(Vite::class)->cspNonce();

        if (is_string($nonce) && $nonce !== '') {
            $vite->useCspNonce($nonce);
        }

        return $vite;
    }

    private function packageHotFile(): string
    {
        return $this->assetPath->absolute().DIRECTORY_SEPARATOR.'hot';
    }

    private function missingAssetsException(ViteException $exception): RuntimeException
    {
        if (
            $exception instanceof ViteManifestNotFoundException
            || str_contains($exception->getMessage(), 'Vite manifest not found')
        ) {
            return new RuntimeException(
                'Horizon New Dawn assets are not published. Run `php artisan horizon-new-dawn:install`.',
                previous: $exception,
            );
        }

        return new RuntimeException(
            'The published Horizon New Dawn asset manifest is invalid. Run `php artisan horizon-new-dawn:install --force`.',
            previous: $exception,
        );
    }
}
