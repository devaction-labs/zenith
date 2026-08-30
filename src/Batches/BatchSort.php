<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches;

enum BatchSort: string
{
    case Name = 'name';
    case TotalJobs = 'totalJobs';
    case PendingJobs = 'pendingJobs';
    case FailedJobs = 'failedJobs';
    case Progress = 'progress';
    case CreatedAt = 'createdAt';
    case QueueActivity = 'queueActivity';
}
