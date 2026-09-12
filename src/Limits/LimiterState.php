<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

use DevactionLabs\Zenith\Limits\Data\LimiterStateData;

/**
 * Read-only view of the GlobalLimit and RateLimit usage EnforceLimitAttributes is
 * currently enforcing for a job class, for display on the queue detail page.
 */
final readonly class LimiterState
{
    public function __construct(private QueueBudget $budget) {}

    public function forJob(string $jobClass, ?string $partitionValue = null): LimiterStateData
    {
        $attributes = LimitAttributes::of($jobClass);

        $globalLimit = $attributes->global?->limit;
        $globalInUse = $globalLimit === null ? null : $this->budget->slotsInUse(
            $attributes->concurrencyName($jobClass, $partitionValue),
            $globalLimit,
        );

        $rateAllowed = $attributes->rate?->allowed;
        $rateRemaining = $rateAllowed === null ? null : $this->budget->remaining(
            $attributes->budgetName($jobClass),
            $rateAllowed,
            $partitionValue,
        );

        return new LimiterStateData(
            jobClass: $jobClass,
            partition: $partitionValue,
            globalLimit: $globalLimit,
            globalInUse: $globalInUse,
            globalRemaining: $globalLimit === null || $globalInUse === null ? null : max(0, $globalLimit - $globalInUse),
            rateAllowed: $rateAllowed,
            ratePeriod: $attributes->rate?->per,
            rateRemaining: $rateRemaining,
        );
    }
}
