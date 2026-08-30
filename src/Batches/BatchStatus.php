<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches;

enum BatchStatus: string
{
    case Pending = 'pending';
    case Finished = 'finished';
    case Failures = 'failures';
    case Cancelled = 'cancelled';
}
