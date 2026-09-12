<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Data;

use DevactionLabs\Zenith\Telemetry\Data\AttemptTimelineData;
use Spatie\LaravelData\Data;

final class JobDetailData extends Data
{
    /**
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $shortName,
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $status,
        public readonly array $tags,
        public readonly int $attempts,
        public readonly ?string $retryOf,
        public readonly ?int $delay,
        public readonly ?float $scheduledAt,
        public readonly ?float $originalScheduledAt,
        public readonly ?string $batchId,
        public readonly ?float $pushedAt,
        public readonly ?float $reservedAt,
        public readonly ?float $completedAt,
        public readonly ?float $failedAt,
        public readonly ?float $runtime,
        public readonly array $payload,
        public readonly AttemptTimelineData $attemptTimeline,
        public readonly bool $retryEligible = false,
        public readonly JobCompositionData $composition = new JobCompositionData(false, false, []),
        public readonly JobAttributesData $attributes = new JobAttributesData(
            null, null, null, false, null, null, null, null, null, null, null, false, false, null, null,
        ),
    ) {}
}
