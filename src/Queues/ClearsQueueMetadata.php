<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

interface ClearsQueueMetadata
{
    public function purgePending(string $connection, string $queue): int;
}
