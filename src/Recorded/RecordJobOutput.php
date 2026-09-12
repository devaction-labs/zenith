<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Recorded;

use Closure;
use DevactionLabs\Zenith\Recorded\Attributes\Recorded as RecordedAttribute;
use Illuminate\Contracts\Queue\Job;
use ReflectionClass;

/**
 * Capture the return value of a job carrying the Recorded attribute and
 * store it under DevactionLabs\Zenith\Recorded\Recorded, keyed by the
 * underlying queue job's UUID.
 *
 * Add it to a job's middleware() alongside #[Recorded]:
 *
 * #[Recorded]
 * final class ImportAccount implements ShouldQueue
 * {
 *     public function middleware(): array
 *     {
 *         return [new RecordJobOutput];
 *     }
 * }
 *
 * A job with no underlying queue job (run outside a worker) has no UUID to
 * record under and runs straight through.
 */
final class RecordJobOutput
{
    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $jobId = $this->jobIdFor($job);

        if ($jobId === null || ! self::isRecorded($job::class)) {
            return $next($job);
        }

        $result = $next($job);

        Recorded::record($jobId, $result);

        return $result;
    }

    private function jobIdFor(object $job): ?string
    {
        $queueJob = property_exists($job, 'job') ? $job->job : null;

        return $queueJob instanceof Job ? $queueJob->uuid() : null;
    }

    private static function isRecorded(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $current = new ReflectionClass($class);

        do {
            if ($current->getAttributes(RecordedAttribute::class) !== []) {
                return true;
            }

            $current = $current->getParentClass();
        } while ($current !== false);

        return false;
    }
}
