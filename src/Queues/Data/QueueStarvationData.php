<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues\Data;

use DevactionLabs\Zenith\Queues\QueueStarvationStatus;
use Spatie\LaravelData\Data;

final class QueueStarvationData extends Data
{
    public function __construct(
        public readonly QueueStarvationStatus $status,
        public readonly int $thresholdSeconds,
        public readonly ?int $oldestReadyAgeSeconds,
    ) {}
}
