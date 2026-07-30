<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Support;

enum ComposerAssetHookResult
{
    case Added;
    case AlreadyPresent;
    case Missing;
    case Malformed;
    case Failed;
}
