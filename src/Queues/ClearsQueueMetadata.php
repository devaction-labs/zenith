<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Queues;

interface ClearsQueueMetadata
{
    public function purgePending(string $connection, string $queue): int;
}
