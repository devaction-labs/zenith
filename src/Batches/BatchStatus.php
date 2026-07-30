<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Batches;

enum BatchStatus: string
{
    case Pending = 'pending';
    case Finished = 'finished';
    case Failures = 'failures';
    case Cancelled = 'cancelled';
}
