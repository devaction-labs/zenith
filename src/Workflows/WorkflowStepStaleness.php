<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use Illuminate\Support\Facades\Date;

/**
 * Whether a workflow step has stalled: still claimed as running or
 * dispatched, but either explicitly marked interrupted by a worker's
 * graceful shutdown (RunWorkflowStep::interrupted()), or simply idle for
 * longer than its own step class's Timeout attribute (or a package
 * default), which most often means the worker that claimed it died without
 * a chance to report anything at all.
 *
 * Shared by WorkflowLifeline, which repairs stale steps, and WorkflowsData,
 * which surfaces them on the workflow detail page.
 */
final readonly class WorkflowStepStaleness
{
    private const int DEFAULT_TIMEOUT_SECONDS = 60;

    /** @var list<string> */
    private const array ACTIVE_STATUSES = [
        WorkflowStatus::Dispatched->value,
        WorkflowStatus::Running->value,
    ];

    public function isStale(WorkflowStep $step): bool
    {
        if (! in_array($step->status, self::ACTIVE_STATUSES, true)) {
            return false;
        }

        if ($step->interrupted_at !== null) {
            return true;
        }

        $updatedAt = $step->updated_at;

        return $updatedAt !== null && $updatedAt->lte(Date::now()->subSeconds($this->timeoutFor($step)));
    }

    public function timeoutFor(WorkflowStep $step): int
    {
        return StepQueueOptions::of($step->job_class)->timeout ?? self::DEFAULT_TIMEOUT_SECONDS;
    }
}
