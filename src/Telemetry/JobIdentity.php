<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

use Illuminate\Contracts\Queue\Job as JobContract;
use Throwable;

/**
 * The queue and application job class a telemetry event is attributed to.
 */
final readonly class JobIdentity
{
    public function __construct(
        public string $queue,
        public string $jobClass,
    ) {}

    public static function fromJob(JobContract $job): self
    {
        $queue = $job->getQueue();

        return new self(
            queue: $queue !== '' ? $queue : 'default',
            jobClass: self::resolveJobClass($job),
        );
    }

    private static function resolveJobClass(JobContract $job): string
    {
        try {
            $class = $job->resolveQueuedJobClass();

            return $class !== '' ? $class : $job->resolveName();
        } catch (Throwable) {
            return $job->resolveName();
        }
    }
}
