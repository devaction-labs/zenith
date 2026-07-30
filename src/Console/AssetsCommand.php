<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Console;

use Illuminate\Console\Command;
use NckRtl\HorizonNewDawn\Assets\AssetPath;
use NckRtl\HorizonNewDawn\Assets\AssetsPublisher;

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
