<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs;

use DevactionLabs\Zenith\Jobs\Data\JobAttributesData;
use DevactionLabs\Zenith\Queues\QueueRouting;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection as ConnectionAttribute;
use Illuminate\Queue\Attributes\DebounceFor;
use Illuminate\Queue\Attributes\Delay as DelayAttribute;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Attributes\WithoutRelations;
use ReflectionClass;
use Throwable;

/**
 * Reads the Laravel 13 queue attributes declared on a job's class via
 * reflection, without instantiating the job. Mirrors the class/parent/trait
 * walk Illuminate\Support\Traits\ReadsClassAttributes performs at runtime, so
 * the values shown here match what Laravel would actually apply.
 */
final class JobAttributes
{
    public static function fromClass(?string $class): JobAttributesData
    {
        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return JobAttributesData::none();
        }

        $backoff = self::attributeInstance($class, Backoff::class);
        $debounce = self::attributeInstance($class, DebounceFor::class);
        $queue = self::attributeInstance($class, QueueAttribute::class);
        $connection = self::attributeInstance($class, ConnectionAttribute::class);
        $route = (new QueueRouting)->forClass($class);

        return new JobAttributesData(
            tries: self::attributeInstance($class, Tries::class)?->tries,
            backoff: $backoff?->backoff,
            timeout: self::attributeInstance($class, Timeout::class)?->timeout,
            failOnTimeout: self::attributeInstance($class, FailOnTimeout::class) !== null,
            maxExceptions: self::attributeInstance($class, MaxExceptions::class)?->maxExceptions,
            uniqueFor: self::attributeInstance($class, UniqueFor::class)?->uniqueFor,
            debounceFor: $debounce?->debounceFor,
            debounceMaxWait: $debounce?->maxWait,
            queue: is_string($queue?->queue) ? $queue->queue : null,
            connection: is_string($connection?->connection) ? $connection->connection : null,
            delay: self::attributeInstance($class, DelayAttribute::class)?->delay,
            withoutRelations: self::attributeInstance($class, WithoutRelations::class) !== null,
            deleteWhenMissingModels: self::attributeInstance($class, DeleteWhenMissingModels::class) !== null,
            routedQueue: $route?->queue,
            routedConnection: $route?->connection,
        );
    }

    /**
     * @template TAttribute of object
     *
     * @param  class-string  $class
     * @param  class-string<TAttribute>  $attributeClass
     * @return TAttribute|null
     */
    private static function attributeInstance(string $class, string $attributeClass): ?object
    {
        $reflection = new ReflectionClass($class);

        do {
            $attributes = $reflection->getAttributes($attributeClass);

            if ($attributes !== []) {
                return self::instantiate($attributes[0]);
            }

            foreach ($reflection->getTraits() as $trait) {
                $traitAttributes = $trait->getAttributes($attributeClass);

                if ($traitAttributes !== []) {
                    return self::instantiate($traitAttributes[0]);
                }
            }

            $parent = $reflection->getParentClass();
            $reflection = $parent === false ? null : $parent;
        } while ($reflection !== null);

        return null;
    }

    /**
     * @template TAttribute of object
     *
     * @param  \ReflectionAttribute<TAttribute>  $attribute
     * @return TAttribute|null
     */
    private static function instantiate(\ReflectionAttribute $attribute): ?object
    {
        try {
            return $attribute->newInstance();
        } catch (Throwable) {
            return null;
        }
    }
}
