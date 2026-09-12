<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

enum QueueStarvationStatus: string
{
    case Starved = 'starved';
    case Monitoring = 'monitoring';
}
