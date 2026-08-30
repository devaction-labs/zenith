<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Queues;

enum QueueWaitThresholdStatus: string
{
    case Exceeded = 'exceeded';
    case Calculating = 'calculating';
    case WithinBounds = 'within_bounds';
    case Disabled = 'disabled';
}
