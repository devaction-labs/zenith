<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use RuntimeException;

final readonly class DispatchWorkflow
{
    public function __construct(
        private AdvanceWorkflow $advance,
    ) {}

    public function handle(WorkflowDefinition $definition): Workflow
    {
        $uniqueKey = $definition->uniqueKey();

        if ($uniqueKey !== null) {
            $existing = Workflow::query()
                ->where('unique_key', $uniqueKey)
                ->whereNotIn('status', [
                    WorkflowStatus::Completed->value,
                    WorkflowStatus::Failed->value,
                    WorkflowStatus::Cancelled->value,
                ])
                ->first();

            if ($existing !== null) {
                throw new RuntimeException("A unique workflow [{$definition->name()}] is already running.");
            }
        }

        $workflow = Workflow::query()->create([
            'name' => $definition->name(),
            'unique_key' => $uniqueKey,
            'status' => WorkflowStatus::Running,
            'context' => $definition->contextValues(),
        ]);

        foreach ($definition->steps() as $step) {
            $workflow->steps()->create([
                'name' => $step['name'],
                'job_class' => $step['job'],
                'payload' => $step['payload'],
                'deps' => $step['deps'],
                'cascade' => $step['cascade'],
                'status' => WorkflowStatus::Pending->value,
            ]);
        }

        $this->advance->dispatchReady($workflow->fresh(['steps']) ?? $workflow);

        return $workflow->fresh(['steps']) ?? $workflow;
    }
}
