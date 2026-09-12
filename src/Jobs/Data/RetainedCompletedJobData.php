<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Data;

/**
 * Internal transfer object carrying only what a retry needs to re-dispatch
 * a retained completed or silenced job: never the caller-facing job row.
 */
final readonly class RetainedCompletedJobData
{
    public function __construct(
        public string $id,
        public string $connection,
        public string $queue,
        public string $rawPayload,
        public ?string $commandClass,
    ) {}
}
