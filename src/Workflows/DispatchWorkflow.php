<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use Illuminate\Database\UniqueConstraintViolationException;

final readonly class DispatchWorkflow
{
    public function __construct(
        private AdvanceWorkflow $advance,
    ) {}

    /**
     * @throws WorkflowAlreadyRunning
     */
    public function handle(WorkflowDefinition $definition): Workflow
    {
        try {
            $workflow = Workflow::query()->getConnection()->transaction(
                fn (): Workflow => $this->persist($definition),
            );
        } catch (UniqueConstraintViolationException $exception) {
            if ($definition->uniqueKey() === null) {
                throw $exception;
            }

            throw WorkflowAlreadyRunning::named($definition->name(), $exception);
        }

        $this->advance->dispatchReady($workflow);

        return $workflow->fresh(['steps']) ?? $workflow;
    }

    /**
     * Persist a workflow and, for every nested step, the pending workflow that the step starts.
     */
    private function persist(WorkflowDefinition $definition, ?Workflow $parent = null, ?string $parentStep = null): Workflow
    {
        $uniqueKey = $definition->uniqueKey();

        if ($uniqueKey !== null) {
            $this->releaseFinishedHolder($uniqueKey);
        }

        $workflow = Workflow::query()->create([
            'name' => $definition->name(),
            'unique_key' => $uniqueKey,
            'status' => $parent === null ? WorkflowStatus::Running : WorkflowStatus::Pending,
            'parent_id' => $parent?->id,
            'parent_step' => $parentStep,
            'context' => $definition->contextValues(),
        ]);

        foreach ($definition->steps() as $step) {
            $workflow->steps()->create([
                'name' => $step['name'],
                'job_class' => $step['job'],
                'payload' => $step['payload'],
                'deps' => $step['deps'],
                'cascade' => $step['cascade'],
                'compensate_job' => $step['compensate'],
                'status' => WorkflowStatus::Pending->value,
            ]);

            if ($step['workflow'] !== null) {
                $this->persist($step['workflow'], $workflow, $step['name']);
            }
        }

        return $workflow;
    }

    /**
     * A finished run keeps its unique key until the next dispatch claims it, so the
     * unique index alone decides which of several overlapping dispatches may run.
     */
    private function releaseFinishedHolder(string $uniqueKey): void
    {
        Workflow::query()
            ->where('unique_key', $uniqueKey)
            ->whereIn('status', WorkflowStatus::finishedValues())
            ->update(['unique_key' => null]);
    }
}
