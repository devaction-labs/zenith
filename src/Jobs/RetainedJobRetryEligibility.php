<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\DebounceFor;
use ReflectionClass;

/**
 * Determines whether a retained job's decoded command class can be safely
 * re-dispatched as a brand-new job.
 *
 * Never instantiates the class: presence of the ShouldBeUnique contract or a
 * DebounceFor attribute is enough to refuse the retry, because verifying an
 * actual lock or debounce window would require calling instance methods such
 * as uniqueId() or debounceId() on a freshly constructed command.
 */
final class RetainedJobRetryEligibility
{
    public function allows(?string $commandClass): bool
    {
        if ($commandClass === null || ! class_exists($commandClass)) {
            return false;
        }

        if (is_a($commandClass, ShouldBeUnique::class, true)) {
            return false;
        }

        return ! $this->hasDebounceContract($commandClass);
    }

    private function hasDebounceContract(string $commandClass): bool
    {
        if (! class_exists($commandClass)) {
            return false;
        }

        return (new ReflectionClass($commandClass))->getAttributes(DebounceFor::class) !== [];
    }
}
