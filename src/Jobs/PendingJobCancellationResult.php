<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Jobs;

enum PendingJobCancellationResult: string
{
    case Cancelled = 'cancelled';
    case NotCancellable = 'not_cancellable';
    case Batched = 'batched';
}
