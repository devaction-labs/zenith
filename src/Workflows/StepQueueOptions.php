<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use ReflectionClass;

/**
 * The Laravel queue attributes declared on a workflow step class, which the job that
 * runs the step adopts so the worker applies the step's retry policy.
 */
final readonly class StepQueueOptions
{
    /**
     * @param  array<int>|int|null  $backoff
     */
    public function __construct(
        public ?int $tries = null,
        public array|int|null $backoff = null,
        public ?int $timeout = null,
        public bool $failOnTimeout = false,
        public ?int $maxExceptions = null,
        public ?string $queue = null,
        public ?string $connection = null,
    ) {}

    public static function of(string $class): self
    {
        if (! class_exists($class)) {
            return new self;
        }

        $reflection = new ReflectionClass($class);
        $queue = self::attribute($reflection, Queue::class)?->queue;
        $connection = self::attribute($reflection, Connection::class)?->connection;

        return new self(
            tries: self::attribute($reflection, Tries::class)?->tries,
            backoff: self::attribute($reflection, Backoff::class)?->backoff,
            timeout: self::attribute($reflection, Timeout::class)?->timeout,
            failOnTimeout: self::attribute($reflection, FailOnTimeout::class) !== null,
            maxExceptions: self::attribute($reflection, MaxExceptions::class)?->maxExceptions,
            queue: is_string($queue) ? $queue : null,
            connection: is_string($connection) ? $connection : null,
        );
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
