<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits\Data;

use Spatie\LaravelData\Data;

/**
 * The current GlobalLimit and RateLimit usage for one job class, optionally scoped to
 * one Partition value. Every field is null when the job class declares no limit of that
 * kind.
 */
final class LimiterStateData extends Data
{
    public function __construct(
        public readonly string $jobClass,
        public readonly ?string $partition,
        public readonly ?int $globalLimit,
        public readonly ?int $globalInUse,
        public readonly ?int $globalRemaining,
        public readonly ?int $rateAllowed,
        public readonly ?int $ratePeriod,
        public readonly ?int $rateRemaining,
    ) {}
}
