<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs;

enum ReleaseDelayedJobNowResult: string
{
    case Released = 'released';
    case NotDelayed = 'not_delayed';
}
