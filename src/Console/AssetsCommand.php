<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Console;

use DevactionLabs\HorizonNewDawn\Assets\AssetPath;
use DevactionLabs\HorizonNewDawn\Assets\AssetsPublisher;
use Illuminate\Console\Command;

final class AssetsCommand extends Command
{
    protected $signature = 'horizon-new-dawn:assets
        {--force : Refresh previously published assets}';

    protected $description = 'Publish the Horizon New Dawn compiled assets';

    public function handle(AssetsPublisher $publisher, AssetPath $assetPath): int
    {
        $publisher->publish(
            destination: $assetPath->absolute(),
            force: (bool) $this->option('force'),
        );

        $this->components->info('Horizon New Dawn assets are ready.');

        return self::SUCCESS;
    }
}
