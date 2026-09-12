<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\AdvanceWorkflow;
use DevactionLabs\Zenith\Workflows\RunWorkflowCompensation;
use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use DevactionLabs\Zenith\Workflows\WorkflowStep;

/**
 * Run a dispatched workflow (faked with Workflow::fake(), faked with Bus::fake(), or real) to
 * completion synchronously, in-process, instead of relying on a real queue worker. Repeatedly
 * runs whichever claimed step or compensation the workflow is waiting on, exactly as the
 * matching job class would, until the workflow reaches a finished status or nothing is left
 * to claim.
 */
function drainWorkflow(Workflow $workflow, int $maxSteps = 100): Workflow
{
    $advance = app(AdvanceWorkflow::class);

    for ($iteration = 0; $iteration < $maxSteps; $iteration++) {
        $workflow->refresh();
        $workflow->loadMissing('steps');

        if (! drainNextWorkflowStep($workflow, $advance)) {
            break;
        }
    }

    return $workflow->refresh();
}

/**
 * Run the next claimed step or compensation of a workflow, returning whether one was found.
 */
function drainNextWorkflowStep(Workflow $workflow, AdvanceWorkflow $advance): bool
{
    $dispatched = $workflow->steps->first(
        static fn (WorkflowStep $step): bool => $step->status === WorkflowStatus::Dispatched->value,
    );

    if ($dispatched instanceof WorkflowStep && $dispatched->job_uuid !== null) {
        RunWorkflowStep::for($workflow->id, $dispatched->name, $dispatched->job_uuid, $dispatched->job_class)
            ->handle($advance);

        return true;
    }

    $compensating = $workflow->steps->first(
        static fn (WorkflowStep $step): bool => $step->status === WorkflowStatus::Compensating->value,
    );

    if ($compensating instanceof WorkflowStep && $compensating->job_uuid !== null && $compensating->compensate_job !== null) {
        RunWorkflowCompensation::for(
            $workflow->id,
            $compensating->name,
            $compensating->job_uuid,
            $compensating->compensate_job,
        )->handle($advance);

        return true;
    }

    return false;
}
