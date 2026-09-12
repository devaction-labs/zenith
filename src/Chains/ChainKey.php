<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chains;

use DevactionLabs\Zenith\Chains\Attributes\ChainBy;
use ReflectionClass;

/**
 * Resolve the chain key a job dispatch belongs to, or null when it declares
 * none.
 */
final readonly class ChainKey
{
    public static function resolve(object $job): ?string
    {
        if (method_exists($job, 'chainKey')) {
            $value = $job->chainKey();

            return is_string($value) ? $value : null;
        }

        $class = $job::class;

        if (! class_exists($class)) {
            return null;
        }

        $attribute = self::attribute(new ReflectionClass($class));

        if ($attribute === null) {
            return null;
        }

        if (property_exists($job, $attribute->key)) {
            $value = $job->{$attribute->key};

            if (is_string($value) || is_int($value)) {
                return (string) $value;
            }
        }

        return $attribute->key;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private static function attribute(ReflectionClass $reflection): ?ChainBy
    {
        $current = $reflection;

        do {
            $attributes = $current->getAttributes(ChainBy::class);

            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }

            $current = $current->getParentClass();
        } while ($current !== false);

        return null;
    }
}
