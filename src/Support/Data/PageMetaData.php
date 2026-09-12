<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Support\Data;

use DevactionLabs\Zenith\Support\NavigationItem;
use Spatie\LaravelData\Data;

final class PageMetaData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly NavigationItem $activeNavigation,
    ) {}
}
