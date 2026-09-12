<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\AdvanceWorkflow;
use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    migrateWorkflowTables();
});

it('implements Interruptible so a queue worker can notify it of a shutdown signal', function (): void {
    expect(new RunWorkflowStep('workflow-1', 'step', 'token'))->toBeInstanceOf(Interruptible::class);
});

it('marks the claimed step interrupted when the worker receives a signal', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('interruptible')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    $job = dispatchedWorkflowStep('configured');
    $job->interrupted(SIGTERM);

    $step = $workflow->steps()->where('name', 'configured')->firstOrFail();

    expect($step->status)->toBe(WorkflowStatus::Dispatched->value)
        ->and($step->interrupted_at)->not->toBeNull();
});

it('ignores a signal for a step no longer claimed by that token', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('stale-token')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    $step = $workflow->steps()->where('name', 'configured')->firstOrFail();

    app(AdvanceWorkflow::class)->markInterrupted($workflow->id, 'configured', 'not-the-real-token');

    expect($step->fresh()?->interrupted_at)->toBeNull();
});

it('does not mark a finished step interrupted', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('finished')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    $step = $workflow->steps()->where('name', 'configured')->firstOrFail();
    $token = $step->job_uuid;

    $step->update(['status' => WorkflowStatus::Completed->value]);

    app(AdvanceWorkflow::class)->markInterrupted($workflow->id, 'configured', (string) $token);

    expect($step->fresh()?->interrupted_at)->toBeNull();
});
