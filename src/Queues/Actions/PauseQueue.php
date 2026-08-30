<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Queues\Actions;

use Carbon\CarbonImmutable;
use DevactionLabs\HorizonNewDawn\Queues\Data\PauseQueueData;
use DevactionLabs\HorizonNewDawn\Queues\QueuePauseMetadata;
use DevactionLabs\HorizonNewDawn\Support\FrameworkCapabilities;
use Illuminate\Queue\QueueManager;

final readonly class PauseQueue
{
    public function __construct(
        private QueueManager $queues,
        private QueuePauseMetadata $metadata,
        private ?FrameworkCapabilities $capabilities = null,
    ) {}

    public function handle(PauseQueueData $data): ?CarbonImmutable
    {
        $capabilities = $this->capabilities ?? FrameworkCapabilities::detect();
        $capabilities->ensureQueuePausing();

        if ($data->durationMinutes !== null && $capabilities->timedQueuePausing) {
            $until = CarbonImmutable::now()->addMinutes($data->durationMinutes);

            $this->queues->pauseFor($data->connection, $data->queue, $until);
            $this->metadata->storeUntil($data->connection, $data->queue, $until);

            return $until;
        }

        $this->queues->pause($data->connection, $data->queue);
        $this->metadata->forget($data->connection, $data->queue);

        return null;
    }
}
