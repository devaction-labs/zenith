<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\BulkOperations;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use RuntimeException;

final readonly class BulkOperationDispatcher
{
    public function __construct(
        private Dispatcher $bus,
        private QueueManager $queues,
    ) {}

    public function dispatch(BulkOperationJob $operation): void
    {
        $queue = $this->queues->connection($operation->connection);

        if ($queue instanceof SyncQueue || $queue instanceof NullQueue) {
            throw new RuntimeException(
                'Zenith bulk operations require an asynchronous queue connection.',
            );
        }

        $this->bus->dispatch($operation);
    }
}
