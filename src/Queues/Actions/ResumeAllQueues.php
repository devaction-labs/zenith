<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues\Actions;

use DevactionLabs\Zenith\Support\FrameworkCapabilities;
use Illuminate\Queue\QueueManager;

final readonly class ResumeAllQueues
{
    public function __construct(
        private QueueManager $queues,
        private ?FrameworkCapabilities $capabilities = null,
    ) {}

    public function handle(): void
    {
        ($this->capabilities ?? FrameworkCapabilities::detect())->ensureQueuePausingAll();

        $this->queues->resumeAll();
    }
}
