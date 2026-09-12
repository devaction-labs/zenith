<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues\Actions;

use DevactionLabs\Zenith\Queues\ClearsQueueMetadata;
use DevactionLabs\Zenith\Queues\Data\QueueTargetData;
use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Queue\QueueManager;
use RuntimeException;

final readonly class ClearQueue
{
    public function __construct(
        private QueueManager $queues,
        private ClearsQueueMetadata $metadata,
    ) {}

    public function handle(QueueTargetData $data): int
    {
        $queue = $this->queues->connection($data->connection);

        if (! $queue instanceof ClearableQueue) {
            throw new RuntimeException("Clearing queues is not supported for {$data->connection}.");
        }

        // Driver clear first. If it throws, Horizon metadata must remain untouched.
        $cleared = $queue->clear($data->queue);

        // Connection-scoped cleanup only — never Horizon's queue-name-only purge().
        $this->metadata->purgePending($data->connection, $data->queue);

        return $cleared;
    }
}
