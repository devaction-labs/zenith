<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

use DevactionLabs\Zenith\Queues\Data\QueueStarvationData;

/**
 * Anti-starvation alerting for a queue's oldest ready job.
 *
 * Builds on QueueWaitThreshold's already-computed oldestReadyAgeSeconds
 * instead of re-deriving job age: a queue is Starved once that age passes
 * zenith.starvation.threshold_seconds, independent of whether wait-time
 * monitoring is enabled or exceeded for that queue, since a queue that is
 * merely slow to clear and a queue whose oldest job is being neglected are
 * different problems worth alerting on separately.
 *
 * This alerts only; it never reprioritizes or moves jobs. See
 * docs/architecture.md for the trade-offs of promoting starved jobs to a
 * higher-priority queue and why this pass ships alerting only.
 */
final readonly class QueueStarvationAlert
{
    private const int DEFAULT_THRESHOLD_SECONDS = 300;

    public function evaluate(?int $oldestReadyAgeSeconds): QueueStarvationData
    {
        $thresholdSeconds = $this->thresholdSeconds();
        $starved = $oldestReadyAgeSeconds !== null && $oldestReadyAgeSeconds > $thresholdSeconds;

        return new QueueStarvationData(
            status: $starved ? QueueStarvationStatus::Starved : QueueStarvationStatus::Monitoring,
            thresholdSeconds: $thresholdSeconds,
            oldestReadyAgeSeconds: $oldestReadyAgeSeconds,
        );
    }

    private function thresholdSeconds(): int
    {
        $configured = config('zenith.starvation.threshold_seconds');

        return is_numeric($configured) ? (int) $configured : self::DEFAULT_THRESHOLD_SECONDS;
    }
}
