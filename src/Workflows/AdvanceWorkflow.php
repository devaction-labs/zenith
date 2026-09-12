<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class AdvanceWorkflow
{
    public function __construct(
        private Container $container,
        private Dispatcher $bus,
    ) {}

    /**
     * Claim and queue every pending step whose dependencies have completed. A step is
     * claimed with a conditional update, so it is queued once even when several workers
     * see its dependencies finish at the same time.
     */
    public function dispatchReady(Workflow $workflow): void
    {
        $workflow->load('steps');

        if ($workflow->status !== WorkflowStatus::Running) {
            return;
        }

        foreach ($workflow->steps as $step) {
            if ($step->status !== WorkflowStatus::Pending->value || ! $this->dependenciesComplete($workflow, $step)) {
                continue;
            }

            $token = Str::uuid()->toString();

            $claimed = $step->transition([WorkflowStatus::Pending], [
                'status' => WorkflowStatus::Dispatched->value,
                'job_uuid' => $token,
            ]);

            if ($claimed) {
                $this->bus->dispatch(new RunWorkflowStep($workflow->id, $step->name, $token));
            }
        }
    }

    /**
     * Run a claimed step. Deliveries for a step that is no longer claimed by the given
     * token, or that already finished, are ignored.
     */
    public function run(string $workflowId, string $stepName, string $token): void
    {
        $workflow = Workflow::query()->with('steps')->find($workflowId);

        if (! $workflow instanceof Workflow || $workflow->status !== WorkflowStatus::Running) {
            return;
        }

        $step = $workflow->steps->firstWhere('name', $stepName);

        if (! $step instanceof WorkflowStep || $step->job_uuid !== $token) {
            return;
        }

        $started = $step->transition([WorkflowStatus::Dispatched, WorkflowStatus::Running], [
            'status' => WorkflowStatus::Running->value,
            'attempts' => $step->attempts + 1,
        ]);

        if (! $started) {
            return;
        }

        try {
            $output = $this->execute($workflow, $step);
        } catch (Throwable $exception) {
            $failed = $step->transition([WorkflowStatus::Running], [
                'status' => WorkflowStatus::Failed->value,
                'error' => $exception->getMessage(),
                'finished_at' => Date::now(),
            ]);

            if ($failed) {
                $this->fail($workflow);
            }

            return;
        }

        $completed = $step->transition([WorkflowStatus::Running], [
            'status' => WorkflowStatus::Completed->value,
            'output' => $output,
            'error' => null,
            'finished_at' => Date::now(),
        ]);

        if ($completed) {
            $this->advance($workflow);
        }
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

        $this->dispatchReady($workflow);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function execute(Workflow $workflow, WorkflowStep $step): array
    {
        $instance = $this->container->make($step->job_class);

        if (! is_object($instance) || ! method_exists($instance, 'handle')) {
            throw new RuntimeException("Workflow step class [{$step->job_class}] has no handle method.");
        }

        $output = $instance->handle($this->payload($workflow, $step), $workflow->context ?? []);

        return is_array($output) ? $output : ['value' => $output];
    }

    private function advance(Workflow $workflow): void
    {
        $workflow->refresh();

        $this->finishIfDone($workflow);
        $this->dispatchReady($workflow);
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

            if ($dependencyStep instanceof WorkflowStep) {
                $payload = [...$dependencyStep->outputValues(), ...$payload];
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

    private function fail(Workflow $workflow): void
    {
        $stopped = $workflow->transition([WorkflowStatus::Running], [
            'status' => WorkflowStatus::Failed,
            'finished_at' => Date::now(),
        ]);

        if (! $stopped) {
            return;
        }

        $workflow->steps()
            ->whereIn('status', WorkflowStatus::activeStepValues())
            ->update([
                'status' => WorkflowStatus::Cancelled->value,
                'finished_at' => Date::now(),
            ]);
    }

    private function finishIfDone(Workflow $workflow): void
    {
        $unfinished = $workflow->steps->contains(
            static fn (WorkflowStep $step): bool => $step->status !== WorkflowStatus::Completed->value,
        );

        if ($unfinished) {
            return;
        }

        $workflow->transition([WorkflowStatus::Running], [
            'status' => WorkflowStatus::Completed,
            'finished_at' => Date::now(),
        ]);
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
