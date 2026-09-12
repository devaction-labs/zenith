<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

use DevactionLabs\Zenith\Signals\SignalWaiting;
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
     * Claim and start every pending step whose dependencies have completed. A step is
     * claimed with a conditional update, so it starts once even when several workers
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

            if (! $claimed) {
                continue;
            }

            if ($step->isNested()) {
                $this->startChild($workflow, $step);

                continue;
            }

            $this->bus->dispatch(RunWorkflowStep::for($workflow->id, $step->name, $token, $step->job_class));
        }
    }

    /**
     * Run a claimed step. Deliveries for a step that is no longer claimed by the given
     * token, or that already finished, are ignored. A failing attempt is recorded and
     * rethrown so the job's retry policy decides whether the step fails, and a step that
     * waits for a signal stays running.
     *
     * @throws Throwable
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

        $started = $step->transition(
            [WorkflowStatus::Dispatched, WorkflowStatus::Running, WorkflowStatus::Retrying],
            ['status' => WorkflowStatus::Running->value, 'attempts' => $step->attempts + 1],
        );

        if (! $started) {
            return;
        }

        try {
            $output = $this->execute($workflow, $step);
        } catch (SignalWaiting $waiting) {
            throw $waiting;
        } catch (Throwable $exception) {
            $step->transition([WorkflowStatus::Running], [
                'status' => WorkflowStatus::Retrying->value,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
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

    /**
     * Run the compensation of a step that was undone after a later step failed.
     *
     * @throws Throwable
     */
    public function runCompensation(string $workflowId, string $stepName, string $token): void
    {
        $workflow = Workflow::query()->with('steps')->find($workflowId);

        if (! $workflow instanceof Workflow) {
            return;
        }

        $step = $workflow->steps->firstWhere('name', $stepName);

        if (! $step instanceof WorkflowStep || $step->job_uuid !== $token) {
            return;
        }

        $compensateJob = $step->compensate_job;

        if ($compensateJob === null || $step->status !== WorkflowStatus::Compensating->value) {
            return;
        }

        try {
            $this->compensateStep($workflow, $step, $compensateJob);
        } catch (Throwable $exception) {
            $step->transition([WorkflowStatus::Compensating], ['error' => $exception->getMessage()]);

            throw $exception;
        }

        $compensated = $step->transition([WorkflowStatus::Compensating], [
            'status' => WorkflowStatus::Compensated->value,
            'error' => null,
            'finished_at' => Date::now(),
        ]);

        if ($compensated) {
            $this->compensate($workflow);
        }
    }

    /**
     * Fail a step whose job ran out of attempts, which fails its workflow.
     */
    public function failStep(string $workflowId, string $stepName, string $token, ?Throwable $exception): void
    {
        $workflow = Workflow::query()->with('steps')->find($workflowId);

        if (! $workflow instanceof Workflow) {
            return;
        }

        $step = $workflow->steps->firstWhere('name', $stepName);

        if (! $step instanceof WorkflowStep || $step->job_uuid !== $token) {
            return;
        }

        $failed = $step->transition(
            [WorkflowStatus::Dispatched, WorkflowStatus::Running, WorkflowStatus::Retrying],
            [
                'status' => WorkflowStatus::Failed->value,
                'error' => $exception?->getMessage() ?? $step->error,
                'finished_at' => Date::now(),
            ],
        );

        if ($failed) {
            $this->fail($workflow);
        }
    }

    /**
     * Record a compensation that ran out of attempts. The remaining compensations wait
     * until this one is retried from the dashboard.
     */
    public function failCompensation(string $workflowId, string $stepName, string $token, ?Throwable $exception): void
    {
        $step = WorkflowStep::query()
            ->where('workflow_id', $workflowId)
            ->where('name', $stepName)
            ->where('job_uuid', $token)
            ->first();

        if (! $step instanceof WorkflowStep) {
            return;
        }

        $step->transition([WorkflowStatus::Compensating], [
            'status' => WorkflowStatus::CompensationFailed->value,
            'error' => $exception?->getMessage() ?? $step->error,
            'finished_at' => Date::now(),
        ]);
    }

    /**
     * Record that the worker running a claimed step received a shutdown signal, without
     * otherwise touching the step. WorkflowLifeline treats an interrupted step as stale on
     * its very next repair pass instead of waiting out the step's full timeout, since a
     * signal is direct evidence the worker is shutting down and will not finish it.
     */
    public function markInterrupted(string $workflowId, string $stepName, string $token): void
    {
        $step = WorkflowStep::query()
            ->where('workflow_id', $workflowId)
            ->where('name', $stepName)
            ->where('job_uuid', $token)
            ->first();

        $step?->transition(
            [WorkflowStatus::Dispatched, WorkflowStatus::Running],
            ['interrupted_at' => Date::now()],
        );
    }

    /**
     * Queue a fresh job for a step that is waiting for a signal, so waiting does not use
     * up the attempts of the job that runs it.
     */
    public function redeliver(string $workflowId, string $stepName, string $token, int $delay): void
    {
        $step = WorkflowStep::query()
            ->where('workflow_id', $workflowId)
            ->where('name', $stepName)
            ->where('job_uuid', $token)
            ->where('status', WorkflowStatus::Running->value)
            ->first();

        if ($step instanceof WorkflowStep) {
            $this->bus->dispatch(
                RunWorkflowStep::for($workflowId, $stepName, $token, $step->job_class)->delay($delay),
            );
        }
    }

    /**
     * Run a workflow again from the given step, or from its first failed step. Steps that
     * were compensated run again too, since their work was undone.
     */
    public function retry(Workflow $workflow, ?string $stepName = null): void
    {
        $workflow->load('steps');

        $step = $stepName === null
            ? $this->firstRetryableStep($workflow)
            : $workflow->steps->firstWhere('name', $stepName);

        if (! $step instanceof WorkflowStep) {
            return;
        }

        if ($step->status === WorkflowStatus::CompensationFailed->value) {
            $this->retryCompensation($workflow, $step);

            return;
        }

        $reset = [$step->name, ...$this->descendants($workflow, $step->name)];

        foreach ($workflow->steps as $candidate) {
            if ($candidate->status === WorkflowStatus::Compensated->value) {
                $reset = [...$reset, $candidate->name, ...$this->descendants($workflow, $candidate->name)];
            }
        }

        $workflow->steps()
            ->whereIn('name', array_values(array_unique($reset)))
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
     * Cancel an unfinished workflow together with its active steps and nested workflows.
     */
    public function cancel(Workflow $workflow): void
    {
        $cancelled = $workflow->transition([WorkflowStatus::Pending, WorkflowStatus::Running], [
            'status' => WorkflowStatus::Cancelled,
            'finished_at' => Date::now(),
        ]);

        if (! $cancelled) {
            return;
        }

        $this->cancelActiveSteps($workflow);
        $this->cancelChildren($workflow);
        $this->failParentStep($workflow, sprintf('Nested workflow [%s] was cancelled.', $this->label($workflow)));
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

    private function compensateStep(Workflow $workflow, WorkflowStep $step, string $compensateJob): void
    {
        $instance = $this->container->make($compensateJob);

        if (! is_object($instance) || ! method_exists($instance, 'handle')) {
            throw new RuntimeException("Workflow compensation class [{$compensateJob}] has no handle method.");
        }

        $instance->handle($this->payload($workflow, $step), $step->outputValues(), $workflow->context ?? []);
    }

    /**
     * Queue the next compensation of a failed workflow. Compensations run one at a time,
     * in reverse completion order, so a step is undone before the steps it depended on.
     */
    private function compensate(Workflow $workflow): void
    {
        $workflow->load('steps');

        $step = $this->nextCompensation($workflow);

        if (! $step instanceof WorkflowStep) {
            return;
        }

        $compensateJob = $step->compensate_job;

        if ($compensateJob === null) {
            return;
        }

        $token = Str::uuid()->toString();

        $claimed = $step->transition([WorkflowStatus::Completed], [
            'status' => WorkflowStatus::Compensating->value,
            'job_uuid' => $token,
        ]);

        if ($claimed) {
            $this->bus->dispatch(
                RunWorkflowCompensation::for($workflow->id, $step->name, $token, $compensateJob),
            );
        }
    }

    private function retryCompensation(Workflow $workflow, WorkflowStep $step): void
    {
        $compensateJob = $step->compensate_job;

        if ($compensateJob === null) {
            return;
        }

        $token = Str::uuid()->toString();

        $claimed = $step->transition([WorkflowStatus::CompensationFailed], [
            'status' => WorkflowStatus::Compensating->value,
            'error' => null,
            'job_uuid' => $token,
            'finished_at' => null,
        ]);

        if ($claimed) {
            $this->bus->dispatch(
                RunWorkflowCompensation::for($workflow->id, $step->name, $token, $compensateJob),
            );
        }
    }

    private function nextCompensation(Workflow $workflow): ?WorkflowStep
    {
        $pending = $workflow->steps
            ->filter(static fn (WorkflowStep $step): bool => $step->status === WorkflowStatus::Completed->value
                && $step->compensate_job !== null)
            ->all();

        if ($pending === []) {
            return null;
        }

        $depths = [];

        foreach ($workflow->steps as $step) {
            $this->depth($workflow, $step, $depths);
        }

        usort(
            $pending,
            static fn (WorkflowStep $first, WorkflowStep $second): int => [
                $second->finished_at?->getTimestamp() ?? 0,
                $depths[$second->name] ?? 0,
                $second->id,
            ] <=> [
                $first->finished_at?->getTimestamp() ?? 0,
                $depths[$first->name] ?? 0,
                $first->id,
            ],
        );

        return $pending[0];
    }

    /**
     * The longest dependency chain that leads to a step, which orders steps that finished
     * within the same second so that a step is undone before its dependencies.
     *
     * @param  array<string, int>  $depths
     */
    private function depth(Workflow $workflow, WorkflowStep $step, array &$depths): int
    {
        if (isset($depths[$step->name])) {
            return $depths[$step->name];
        }

        $depth = 0;

        foreach ($step->dependencies() as $dependency) {
            $dependencyStep = $workflow->steps->firstWhere('name', $dependency);

            if ($dependencyStep instanceof WorkflowStep) {
                $depth = max($depth, $this->depth($workflow, $dependencyStep, $depths) + 1);
            }
        }

        return $depths[$step->name] = $depth;
    }

    private function firstRetryableStep(Workflow $workflow): ?WorkflowStep
    {
        return $workflow->steps->first(static fn (WorkflowStep $step): bool => in_array($step->status, [
            WorkflowStatus::Failed->value,
            WorkflowStatus::CompensationFailed->value,
        ], true));
    }

    /**
     * Start the nested workflow of a claimed step. Steps that did not complete in an
     * earlier run start again, so retrying the parent step resumes the nested workflow.
     */
    private function startChild(Workflow $parent, WorkflowStep $step): void
    {
        $started = $step->transition([WorkflowStatus::Dispatched], [
            'status' => WorkflowStatus::Running->value,
            'attempts' => $step->attempts + 1,
        ]);

        if (! $started) {
            return;
        }

        $child = $parent->children()->where('parent_step', $step->name)->first();

        if (! $child instanceof Workflow) {
            $failed = $step->transition([WorkflowStatus::Running], [
                'status' => WorkflowStatus::Failed->value,
                'error' => "The nested workflow of step [{$step->name}] is missing.",
                'finished_at' => Date::now(),
            ]);

            if ($failed) {
                $this->fail($parent);
            }

            return;
        }

        $child->steps()
            ->where('status', '!=', WorkflowStatus::Completed->value)
            ->update([
                'status' => WorkflowStatus::Pending->value,
                'error' => null,
                'output' => null,
                'job_uuid' => null,
                'finished_at' => null,
            ]);

        $child->forceFill([
            'status' => WorkflowStatus::Running,
            'finished_at' => null,
        ])->save();

        $this->advance($child);
    }

    private function advance(Workflow $workflow): void
    {
        $workflow->refresh();
        $workflow->loadMissing('steps');

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

        $this->cancelActiveSteps($workflow);
        $this->cancelChildren($workflow);
        $this->failParentStep($workflow, sprintf('Nested workflow [%s] failed.', $this->label($workflow)));
        $this->compensate($workflow);
    }

    private function finishIfDone(Workflow $workflow): void
    {
        $unfinished = $workflow->steps->contains(
            static fn (WorkflowStep $step): bool => $step->status !== WorkflowStatus::Completed->value,
        );

        if ($unfinished) {
            return;
        }

        $completed = $workflow->transition([WorkflowStatus::Running], [
            'status' => WorkflowStatus::Completed,
            'finished_at' => Date::now(),
        ]);

        if ($completed) {
            $this->completeParentStep($workflow);
        }
    }

    private function cancelActiveSteps(Workflow $workflow): void
    {
        $workflow->steps()
            ->whereIn('status', WorkflowStatus::activeStepValues())
            ->update([
                'status' => WorkflowStatus::Cancelled->value,
                'finished_at' => Date::now(),
            ]);
    }

    private function cancelChildren(Workflow $workflow): void
    {
        $children = $workflow->children()
            ->whereIn('status', [WorkflowStatus::Pending->value, WorkflowStatus::Running->value])
            ->get();

        foreach ($children as $child) {
            $this->cancel($child);
        }
    }

    private function completeParentStep(Workflow $child): void
    {
        $step = $this->parentStepOf($child);

        if ($step === null) {
            return;
        }

        $completed = $step->transition([WorkflowStatus::Running], [
            'status' => WorkflowStatus::Completed->value,
            'output' => $this->childOutput($child),
            'error' => null,
            'finished_at' => Date::now(),
        ]);

        $parent = Workflow::query()->find($step->workflow_id);

        if ($completed && $parent instanceof Workflow) {
            $this->advance($parent);
        }
    }

    private function failParentStep(Workflow $child, string $error): void
    {
        $step = $this->parentStepOf($child);

        if ($step === null) {
            return;
        }

        $failed = $step->transition(
            [WorkflowStatus::Pending, WorkflowStatus::Dispatched, WorkflowStatus::Running],
            [
                'status' => WorkflowStatus::Failed->value,
                'error' => $error,
                'finished_at' => Date::now(),
            ],
        );

        $parent = Workflow::query()->find($step->workflow_id);

        if ($failed && $parent instanceof Workflow) {
            $this->fail($parent);
        }
    }

    private function parentStepOf(Workflow $child): ?WorkflowStep
    {
        if ($child->parent_id === null || $child->parent_step === null) {
            return null;
        }

        return WorkflowStep::query()
            ->where('workflow_id', $child->parent_id)
            ->where('name', $child->parent_step)
            ->first();
    }

    /**
     * The merged outputs of a nested workflow's completed steps, in step order.
     *
     * @return array<string, mixed>
     */
    private function childOutput(Workflow $child): array
    {
        $output = [];

        foreach ($child->steps()->get() as $step) {
            if ($step->status === WorkflowStatus::Completed->value) {
                $output = [...$output, ...$step->outputValues()];
            }
        }

        return $output;
    }

    private function label(Workflow $workflow): string
    {
        return $workflow->name ?? $workflow->id;
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
