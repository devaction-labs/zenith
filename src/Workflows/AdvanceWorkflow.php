<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Date;
use Throwable;

final readonly class AdvanceWorkflow
{
    public function __construct(
        private Container $container,
        private Dispatcher $bus,
    ) {}

    public function dispatchReady(Workflow $workflow): void
    {
        $workflow->load('steps');

        foreach ($workflow->steps as $step) {
            if ($step->status !== WorkflowStatus::Pending->value) {
                continue;
            }

            if (! $this->dependenciesComplete($workflow, $step)) {
                continue;
            }

            $this->queue($workflow, $step);
        }
    }

    public function run(string $workflowId, string $stepName): void
    {
        $workflow = Workflow::query()->with('steps')->find($workflowId);

        if ($workflow === null || $workflow->status->finished()) {
            return;
        }

        $step = $workflow->steps->firstWhere('name', $stepName);

        if (! $step instanceof WorkflowStep || $step->status === WorkflowStatus::Cancelled->value) {
            return;
        }

        $step->forceFill([
            'status' => WorkflowStatus::Running->value,
            'attempts' => $step->attempts + 1,
        ])->save();

        try {
            $payload = $this->payload($workflow, $step);
            $instance = $this->container->make($step->job_class);
            $output = $instance->handle($payload, $workflow->context ?? []);

            $step->forceFill([
                'status' => WorkflowStatus::Completed->value,
                'output' => is_array($output) ? $output : ['value' => $output],
                'error' => null,
                'finished_at' => Date::now(),
            ])->save();
        } catch (Throwable $exception) {
            $step->forceFill([
                'status' => WorkflowStatus::Failed->value,
                'error' => $exception->getMessage(),
                'finished_at' => Date::now(),
            ])->save();

            $this->fail($workflow, $step);

            return;
        }

        $workflow->refresh()->load('steps');
        $this->finishIfDone($workflow);
        $this->dispatchReady($workflow);
    }

    public function retry(Workflow $workflow, string $stepName): void
    {
        $workflow->load('steps');
        $step = $workflow->steps->firstWhere('name', $stepName);

        if (! $step instanceof WorkflowStep) {
            return;
        }

        $reset = [$stepName, ...$this->descendants($workflow, $stepName)];

        $workflow->steps()
            ->whereIn('name', $reset)
            ->update([
                'status' => WorkflowStatus::Pending->value,
                'error' => null,
                'output' => null,
                'job_uuid' => null,
                'finished_at' => null,
            ]);

        $workflow->forceFill([
            'status' => WorkflowStatus::Running,
            'finished_at' => null,
        ])->save();

        $this->dispatchReady($workflow->fresh(['steps']) ?? $workflow);
    }

    private function queue(Workflow $workflow, WorkflowStep $step): void
    {
        $job = new RunWorkflowStep($workflow->id, $step->name);

        $step->forceFill([
            'status' => WorkflowStatus::Dispatched->value,
        ])->save();

        $this->bus->dispatch($job);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Workflow $workflow, WorkflowStep $step): array
    {
        $payload = $step->payload ?? [];

        if (! $step->cascade) {
            return $payload;
        }

        foreach ($step->dependencies() as $dependency) {
            $dependencyStep = $workflow->steps->firstWhere('name', $dependency);
            $output = $dependencyStep instanceof WorkflowStep ? $dependencyStep->output : [];

            if (is_array($output)) {
                $payload = [...$output, ...$payload];
            }
        }

        return $payload;
    }

    private function dependenciesComplete(Workflow $workflow, WorkflowStep $step): bool
    {
        foreach ($step->dependencies() as $dependency) {
            $dep = $workflow->steps->firstWhere('name', $dependency);

            if (! $dep instanceof WorkflowStep || $dep->status !== WorkflowStatus::Completed->value) {
                return false;
            }
        }

        return true;
    }

    private function fail(Workflow $workflow, WorkflowStep $failed): void
    {
        $workflow->forceFill([
            'status' => WorkflowStatus::Failed,
            'finished_at' => Date::now(),
        ])->save();

        $workflow->steps()
            ->where('name', '!=', $failed->name)
            ->whereNotIn('status', [
                WorkflowStatus::Completed->value,
                WorkflowStatus::Failed->value,
                WorkflowStatus::Cancelled->value,
            ])
            ->update([
                'status' => WorkflowStatus::Cancelled->value,
                'finished_at' => Date::now(),
            ]);
    }

    private function finishIfDone(Workflow $workflow): void
    {
        $unfinished = $workflow->steps->contains(
            fn (WorkflowStep $step): bool => ! in_array($step->status, [
                WorkflowStatus::Completed->value,
                WorkflowStatus::Failed->value,
                WorkflowStatus::Cancelled->value,
            ], true),
        );

        if ($unfinished) {
            return;
        }

        $failed = $workflow->steps->contains(
            fn (WorkflowStep $step): bool => $step->status === WorkflowStatus::Failed->value,
        );

        $workflow->forceFill([
            'status' => $failed ? WorkflowStatus::Failed : WorkflowStatus::Completed,
            'finished_at' => Date::now(),
        ])->save();
    }

    /**
     * @return list<string>
     */
    private function descendants(Workflow $workflow, string $stepName): array
    {
        $found = [];

        foreach ($workflow->steps as $step) {
            if (in_array($stepName, $step->dependencies(), true)) {
                $found[] = $step->name;
                $found = [...$found, ...$this->descendants($workflow, $step->name)];
            }
        }

        return array_values(array_unique($found));
    }
}
