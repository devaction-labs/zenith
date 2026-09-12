<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class RunningJobsPageData extends Data
{
    /**
     * @param  list<RunningJobData>  $jobs
     * @param  list<RunningJobsNodeSummaryData>  $nodeSummary
     */
    public function __construct(
        public readonly bool $available,
        public readonly array $jobs,
        public readonly array $nodeSummary,
        public readonly ?string $message,
    ) {}
}
