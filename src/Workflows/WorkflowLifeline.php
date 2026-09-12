<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use Illuminate\Contracts\Bus\Dispatcher;
use RuntimeException;

/**
 * Repairs workflow steps stuck in running or dispatched: those a worker never got to
 * finish before dying, timing out, or being killed after a graceful shutdown signal.
 *
 * Policy: a stale step whose attempts have not yet reached its step class's Tries
 * attribute (or a package default of 3) is re-dispatched with its existing claim token,
 * exactly as if the original job had released itself; a step that has already reached
 * that ceiling is instead failed, which cascades through AdvanceWorkflow::failStep() the
 * same way any other permanently failed step does (cancelling active steps, cancelling
 * nested workflows, and running compensations).
 *
 * Re-dispatching does not first change the step's status, since AdvanceWorkflow::run()
 * already accepts a claim from Dispatched, Running, or Retrying. This means a step whose
 * original worker was merely slow rather than truly gone can still finish and complete
 * the step at the same time the freshly re-dispatched copy runs it again — the same
 * at-least-once trade-off RunWorkflowStep's own signal-wait redelivery already accepts,
 * not a new risk this lifeline introduces. Step handlers are expected to tolerate that,
 * the same way any queued job's handle() must tolerate more than one delivery.
 */
final readonly class WorkflowLifeline
{
    private const int DEFAULT_MAX_ATTEMPTS = 3;

    private const int REDISPATCH_DELAY_SECONDS = 0;

    public function __construct(
        private Dispatcher $bus,
        private AdvanceWorkflow $advance,
        private WorkflowStepStaleness $staleness,
    ) {}

    /**
     * @return int The number of steps repaired.
     */
    public function repair(): int
    {
        $repaired = 0;

        WorkflowStep::query()
            ->whereIn('status', [WorkflowStatus::Running->value, WorkflowStatus::Dispatched->value])
            ->whereNotNull('job_uuid')
            ->orderBy('id')
            ->cursor()
            ->each(function (WorkflowStep $step) use (&$repaired): void {
                if (! $this->staleness->isStale($step)) {
                    return;
                }

                $this->repairStep($step);
                $repaired++;
            });

        return $repaired;
    }

    private function repairStep(WorkflowStep $step): void
    {
        $token = $step->job_uuid;

        if ($token === null) {
            return;
        }

        if ($step->attempts >= $this->maxAttemptsFor($step)) {
            $this->advance->failStep(
                $step->workflow_id,
                $step->name,
                $token,
                new RuntimeException(sprintf(
                    'Workflow step [%s] stalled in [%s] and exhausted its repair attempts.',
                    $step->name,
                    $step->status,
                )),
            );

            return;
        }

        $step->forceFill(['interrupted_at' => null])->save();

        $this->bus->dispatch(
            RunWorkflowStep::for($step->workflow_id, $step->name, $token, $step->job_class)
                ->delay(self::REDISPATCH_DELAY_SECONDS),
        );
    }

    private function maxAttemptsFor(WorkflowStep $step): int
    {
        return StepQueueOptions::of($step->job_class)->tries ?? self::DEFAULT_MAX_ATTEMPTS;
    }
}
