<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/workbench',
    ])
    ->withSkip([
        __DIR__.'/vendor',
        __DIR__.'/dist',
        __DIR__.'/resources/js/generated',
    ])
    ->withComposerBased(laravel: true);
