<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

enum QueueActivityTab: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Silenced = 'silenced';
    case Batches = 'batches';
}
