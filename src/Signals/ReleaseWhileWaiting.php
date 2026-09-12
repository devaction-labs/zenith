<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Signals;

use Closure;
use Illuminate\Contracts\Queue\Job;

/**
 * Job middleware for jobs that wait on signals.
 *
 * It exposes the running queue job to Signal::await() so a wait can release it, and
 * treats the resulting SignalWaiting as a completed release instead of a failure.
 * Every redelivery still counts as an attempt, so give waiting jobs a retryUntil()
 * deadline instead of a fixed $tries.
 */
final readonly class ReleaseWhileWaiting
{
    public function handle(object $command, Closure $next): mixed
    {
        $job = property_exists($command, 'job') ? $command->job : null;

        if (! $job instanceof Job) {
            return $next($command);
        }

        $container = app();
        $previous = $container->bound(Job::class) ? $container->make(Job::class) : null;
        $container->instance(Job::class, $job);

        try {
            return $next($command);
        } catch (SignalWaiting) {
            return null;
        } finally {
            if ($previous === null) {
                $container->forgetInstance(Job::class);
            } else {
                $container->instance(Job::class, $previous);
            }
        }
    }
}
