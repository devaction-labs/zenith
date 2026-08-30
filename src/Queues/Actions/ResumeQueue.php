<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Queues\Actions;

use DevactionLabs\HorizonNewDawn\Queues\QueuePauseMetadata;
use DevactionLabs\HorizonNewDawn\Support\FrameworkCapabilities;
use Illuminate\Queue\QueueManager;

final readonly class ResumeQueue
{
    public function __construct(
        private QueueManager $queues,
        private QueuePauseMetadata $metadata,
        private ?FrameworkCapabilities $capabilities = null,
    ) {}

    public function handle(string $connection, string $queue): void
    {
        ($this->capabilities ?? FrameworkCapabilities::detect())->ensureQueuePausing();

        $this->queues->resume($connection, $queue);
        $this->metadata->forget($connection, $queue);
    }
}
