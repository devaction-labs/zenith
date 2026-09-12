<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits;

use DevactionLabs\Zenith\Limits\Attributes\GlobalLimit;
use DevactionLabs\Zenith\Limits\Attributes\Partition;
use DevactionLabs\Zenith\Limits\Attributes\RateLimit;
use ReflectionClass;

/**
 * The GlobalLimit, RateLimit, and Partition attributes declared on a job class, and the
 * budget key derivation shared by EnforceLimitAttributes and LimiterState so both read
 * the same limiter state.
 */
final readonly class LimitAttributes
{
    public function __construct(
        public ?GlobalLimit $global,
        public ?RateLimit $rate,
        public ?Partition $partition,
    ) {}

    public static function of(string $class): self
    {
        if (! class_exists($class)) {
            return new self(null, null, null);
        }

        $reflection = new ReflectionClass($class);

        return new self(
            self::attribute($reflection, GlobalLimit::class),
            self::attribute($reflection, RateLimit::class),
            self::attribute($reflection, Partition::class),
        );
    }

    public function hasLimits(): bool
    {
        return $this->global !== null || $this->rate !== null;
    }

    /**
     * The partition value read off the job instance's constructor-promoted property
     * named by the Partition attribute, or null when there is no Partition attribute or
     * the property does not hold a string or integer value.
     */
    public function partitionValue(object $job): ?string
    {
        if ($this->partition === null) {
            return null;
        }

        $key = $this->partition->argumentKey;

        if (! property_exists($job, $key)) {
            return null;
        }

        $value = $job->{$key};

        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            default => null,
        };
    }

    /**
     * The QueueBudget name shared by the rate limit and, unpartitioned, the global limit.
     */
    public function budgetName(string $class): string
    {
        return str_replace('\\', ':', $class);
    }

    /**
     * The QueueBudget name used for the global concurrency limit, folding the partition
     * value in directly since QueueBudget's concurrency slots have no partition of their own.
     */
    public function concurrencyName(string $class, ?string $partitionValue): string
    {
        $name = $this->budgetName($class);

        return $partitionValue === null ? $name : $name.':'.$partitionValue;
    }

    /**
     * @template TAttribute of object
     *
     * @param  ReflectionClass<object>  $reflection
     * @param  class-string<TAttribute>  $attribute
     * @return TAttribute|null
     */
    private static function attribute(ReflectionClass $reflection, string $attribute): ?object
    {
        $current = $reflection;

        do {
            $attributes = $current->getAttributes($attribute);

            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }

            $current = $current->getParentClass();
        } while ($current !== false);

        return null;
    }
}
