<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\FailedJobs\Data;

use DevactionLabs\Zenith\Jobs\Data\JobAttributesData;
use DevactionLabs\Zenith\Jobs\Data\JobCompositionData;
use DevactionLabs\Zenith\Telemetry\Data\AttemptTimelineData;
use Spatie\LaravelData\Data;

final class FailedJobDetailData extends Data
{
    /**
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $payload
     * @param  array<array-key, mixed>  $context
     * @param  array<int, FailedJobRetryData>  $retriedBy
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $shortName,
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $status,
        public readonly array $tags,
        public readonly ?float $pushedAt,
        public readonly ?float $reservedAt,
        public readonly ?float $failedAt,
        public readonly ?float $runtime,
        public readonly int $attempts,
        public readonly ?string $retryOf,
        public readonly ?int $delay,
        public readonly ?float $scheduledAt,
        public readonly ?float $originalScheduledAt,
        public readonly ?string $batchId,
        public readonly bool $retried,
        public readonly array $retriedBy,
        public readonly bool $retryEligible,
        public readonly array $payload,
        public readonly array $context,
        public readonly string $exception,
        public readonly AttemptTimelineData $attemptTimeline,
        public readonly JobCompositionData $composition = new JobCompositionData(false, false, []),
        public readonly JobAttributesData $attributes = new JobAttributesData(
            null, null, null, false, null, null, null, null, null, null, null, false, false, null, null,
        ),
        public readonly bool $canExplainFailure = false,
    ) {}
}
