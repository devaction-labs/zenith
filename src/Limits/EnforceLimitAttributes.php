<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

use Closure;
use DevactionLabs\Zenith\Limits\Attributes\GlobalLimit;
use DevactionLabs\Zenith\Limits\Attributes\RateLimit;

/**
 * Apply the GlobalLimit, RateLimit, and Partition attributes declared on a queued job's
 * class, so a job opts into Zenith's queue budget with attributes alone instead of
 * building EnforceQueueBudget and EnforceQueueConcurrency instances by hand.
 *
 * The rate limit is checked first, since it is the cheaper rejection, then the global
 * concurrency slot is acquired. Add it to a job's middleware() with no arguments:
 *
 * #[GlobalLimit(limit: 5)]
 * #[RateLimit(allowed: 100, per: 60)]
 * final class ImportAccount implements ShouldQueue
 * {
 *     public function middleware(): array
 *     {
 *         return [new EnforceLimitAttributes];
 *     }
 * }
 */
final class EnforceLimitAttributes
{
    public function __construct(private readonly ?QueueBudget $budget = null) {}

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $attributes = LimitAttributes::of($job::class);

        if (! $attributes->hasLimits()) {
            return $next($job);
        }

        $partitionValue = $attributes->partitionValue($job);
        $budget = $this->budget ?? app(QueueBudget::class);
        $pipeline = $next;

        if ($attributes->global instanceof GlobalLimit) {
            $concurrency = new EnforceQueueConcurrency(
                $budget,
                $attributes->concurrencyName($job::class, $partitionValue),
                $attributes->global->limit,
                $attributes->global->expiresAfter,
            );
            $inner = $pipeline;
            $pipeline = static fn (object $job): mixed => $concurrency->handle($job, $inner);
        }

        if ($attributes->rate instanceof RateLimit) {
            $rateLimit = new EnforceQueueBudget(
                $budget,
                $attributes->budgetName($job::class),
                $attributes->rate->allowed,
                $attributes->rate->per,
                partition: $partitionValue,
            );
            $inner = $pipeline;
            $pipeline = static fn (object $job): mixed => $rateLimit->handle($job, $inner);
        }

        return $pipeline($job);
    }
}
