<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Support;

enum ComposerAssetHookResult
{
    case Added;
    case AlreadyPresent;
    case Missing;
    case Malformed;
    case Failed;
}
