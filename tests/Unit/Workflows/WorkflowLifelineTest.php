<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowLifeline;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    migrateWorkflowTables();
    Bus::fake();
});

function dispatchedWorkflowStepStale(string $workflow, string $step, int $ageSeconds = 61): void
{
    $step = Workflow::query()->findOrFail($workflow)->steps()->where('name', $step)->firstOrFail();
    $step->forceFill(['updated_at' => now()->subSeconds($ageSeconds)])->saveQuietly();
}

it('leaves a freshly dispatched step alone', function (): void {
    $workflow = WorkflowDefinition::make('fresh')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    $repaired = app(WorkflowLifeline::class)->repair();

    expect($repaired)->toBe(0)
        ->and($workflow->steps()->where('name', 'configured')->value('status'))
        ->toBe(WorkflowStatus::Dispatched->value);

    Bus::assertDispatchedTimes(RunWorkflowStep::class, 1);
});

it('re-dispatches a stale running step with the same claim token', function (): void {
    $workflow = WorkflowDefinition::make('stale-running')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    $step = $workflow->steps()->where('name', 'configured')->firstOrFail();
    $step->transition([WorkflowStatus::Dispatched], ['status' => WorkflowStatus::Running->value, 'attempts' => 1]);
    dispatchedWorkflowStepStale($workflow->id, 'configured');

    $repaired = app(WorkflowLifeline::class)->repair();

    expect($repaired)->toBe(1);

    Bus::assertDispatched(
        RunWorkflowStep::class,
        fn (RunWorkflowStep $job): bool => $job->workflowId === $workflow->id
            && $job->stepName === 'configured'
            && $job->token === $step->job_uuid,
    );
});

it('re-dispatches a stale dispatched step that never started', function (): void {
    $workflow = WorkflowDefinition::make('stale-dispatched')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    dispatchedWorkflowStepStale($workflow->id, 'configured');

    $repaired = app(WorkflowLifeline::class)->repair();

    expect($repaired)->toBe(1);
    Bus::assertDispatchedTimes(RunWorkflowStep::class, 2);
});

it('clears the interrupted marker once a step is re-dispatched', function (): void {
    $workflow = WorkflowDefinition::make('interrupted')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    $step = $workflow->steps()->where('name', 'configured')->firstOrFail();
    $step->forceFill(['interrupted_at' => now()])->save();

    app(WorkflowLifeline::class)->repair();

    expect($step->fresh()?->interrupted_at)->toBeNull();
});

it('fails a step that has exhausted its repair attempts and cascades the workflow failure', function (): void {
    $workflow = WorkflowDefinition::make('exhausted-repair')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->add('after', ProcessWorkflowStep::class, deps: ['configured'])
        ->dispatch();

    $step = $workflow->steps()->where('name', 'configured')->firstOrFail();
    $step->transition([WorkflowStatus::Dispatched], ['status' => WorkflowStatus::Running->value, 'attempts' => 3]);
    dispatchedWorkflowStepStale($workflow->id, 'configured');

    $repaired = app(WorkflowLifeline::class)->repair();

    expect($repaired)->toBe(1)
        ->and($step->fresh()?->status)->toBe(WorkflowStatus::Failed->value)
        ->and($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed)
        ->and($workflow->steps()->where('name', 'after')->value('status'))
        ->toBe(WorkflowStatus::Cancelled->value);

    Bus::assertDispatchedTimes(RunWorkflowStep::class, 1);
});

it('ignores a step that is stale but no longer claimed by any token', function (): void {
    $workflow = WorkflowDefinition::make('no-token')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    $step = $workflow->steps()->where('name', 'configured')->firstOrFail();
    $step->forceFill(['job_uuid' => null])->save();
    dispatchedWorkflowStepStale($workflow->id, 'configured');

    expect(app(WorkflowLifeline::class)->repair())->toBe(0);
});

it('repairs every stale step across several workflows in one pass', function (): void {
    $first = WorkflowDefinition::make('batch-one')->add('configured', ConfiguredWorkflowStep::class)->dispatch();
    $second = WorkflowDefinition::make('batch-two')->add('configured', ConfiguredWorkflowStep::class)->dispatch();

    dispatchedWorkflowStepStale($first->id, 'configured');
    dispatchedWorkflowStepStale($second->id, 'configured');

    expect(app(WorkflowLifeline::class)->repair())->toBe(2);
});
