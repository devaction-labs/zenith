<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use DevactionLabs\Zenith\Workflows\Data\WorkflowDetailData;
use DevactionLabs\Zenith\Workflows\Data\WorkflowRowData;
use DevactionLabs\Zenith\Workflows\Data\WorkflowStepData;
use Illuminate\Support\Facades\Schema;

final class WorkflowsData
{
    public function available(): bool
    {
        return Schema::hasTable('zenith_workflows');
    }

    /**
     * @return list<WorkflowRowData>
     */
    public function list(): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_values(
            Workflow::query()
                ->with('steps')
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (Workflow $workflow): WorkflowRowData => $this->row($workflow))
                ->all(),
        );
    }

    public function find(string $id): ?WorkflowDetailData
    {
        if (! $this->available()) {
            return null;
        }

        $workflow = Workflow::query()->with('steps')->find($id);

        if (! $workflow instanceof Workflow) {
            return null;
        }

        $steps = $workflow->steps->map(fn (WorkflowStep $step): WorkflowStepData => new WorkflowStepData(
            name: $step->name,
            jobClass: $step->job_class,
            deps: $step->dependencies(),
            cascade: $step->cascade,
            status: $step->status,
            output: is_array($step->output) ? $step->output : null,
            error: $step->error,
            attempts: $step->attempts,
            finishedAt: $step->finished_at?->getTimestamp(),
        ))->all();

        $failed = $workflow->steps->contains(
            fn (WorkflowStep $step): bool => $step->status === WorkflowStatus::Failed->value,
        );

        return new WorkflowDetailData(
            id: $workflow->id,
            name: $workflow->name,
            status: $workflow->status->value,
            context: $workflow->context ?? [],
            steps: array_values($steps),
            createdAt: $workflow->created_at?->getTimestamp(),
            finishedAt: $workflow->finished_at?->getTimestamp(),
            cancellable: ! $workflow->status->finished(),
            retryable: $failed,
        );
    }

    private function row(Workflow $workflow): WorkflowRowData
    {
        return new WorkflowRowData(
            id: $workflow->id,
            name: $workflow->name,
            status: $workflow->status->value,
            stepCount: $workflow->steps->count(),
            completedSteps: $workflow->steps->where('status', WorkflowStatus::Completed->value)->count(),
            createdAt: $workflow->created_at?->getTimestamp(),
            finishedAt: $workflow->finished_at?->getTimestamp(),
        );
    }
}
