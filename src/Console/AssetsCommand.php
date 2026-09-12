<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Console;

use DevactionLabs\Zenith\Assets\AssetPath;
use DevactionLabs\Zenith\Assets\AssetsPublisher;
use Illuminate\Console\Command;

final class AssetsCommand extends Command
{
    protected $signature = 'zenith:assets
        {--force : Refresh previously published assets}';

    protected $description = 'Publish the Zenith compiled assets';

    public function handle(AssetsPublisher $publisher, AssetPath $assetPath): int
    {
        $publisher->publish(
            destination: $assetPath->absolute(),
            force: (bool) $this->option('force'),
        );

        $this->components->info('Zenith assets are ready.');

        return self::SUCCESS;
    }
}
