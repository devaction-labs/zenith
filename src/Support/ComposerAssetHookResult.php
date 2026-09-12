<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Support;

enum ComposerAssetHookResult
{
    case Added;
    case AlreadyPresent;
    case Missing;
    case Malformed;
    case Failed;
}
