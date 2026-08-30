<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Jobs;

enum ReleaseDelayedJobNowResult: string
{
    case Released = 'released';
    case NotDelayed = 'not_delayed';
}
