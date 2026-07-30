<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Assets;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final readonly class AssetPath
{
    private const string RELATIVE_PATH = 'vendor/horizon-new-dawn/build';

    public function __construct(
        private Application $application,
    ) {}

    public function relative(): string
    {
        return self::RELATIVE_PATH;
    }

    public function absolute(): string
    {
        $relativePath = $this->relative();
        $publicPath = realpath($this->application->publicPath());

        if (! is_string($publicPath)) {
            throw new RuntimeException('The public directory could not be resolved for Horizon New Dawn assets.');
        }

        $currentPath = $publicPath;

        foreach (explode('/', $relativePath) as $segment) {
            $currentPath .= DIRECTORY_SEPARATOR.$segment;

            if (! file_exists($currentPath) && ! is_link($currentPath)) {
                continue;
            }

            $resolvedPath = realpath($currentPath);

            if (
                ! is_string($resolvedPath)
                || $resolvedPath === $publicPath
                || ! str_starts_with($resolvedPath, $publicPath.DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException('The Horizon New Dawn asset path must resolve within the public directory.');
            }
        }

        return $publicPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function manifest(): string
    {
        return $this->absolute().DIRECTORY_SEPARATOR.'manifest.json';
    }
}
