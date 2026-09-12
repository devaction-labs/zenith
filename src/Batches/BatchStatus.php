<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches;

enum BatchStatus: string
{
    case Pending = 'pending';
    case Finished = 'finished';
    case Failures = 'failures';
    case Cancelled = 'cancelled';
}
