<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

use Illuminate\Queue\Events\QueueFailedOver;

final readonly class RecordQueueFailover
{
    public function __construct(
        private QueueFailoverActivity $activity,
    ) {}

    public function __invoke(QueueFailedOver $event): void
    {
        $this->activity->record($event->connectionName);
    }
}
