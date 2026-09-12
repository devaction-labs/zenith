<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    migrateWorkflowTables();
    CompensateWorkflowStep::$released = [];
});

it('drains a workflow faked with Workflow::fake() to completion', function (): void {
    Workflow::fake();

    $workflow = WorkflowDefinition::make('pipeline')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1, 2, 3]])
        ->cascade('process', ProcessWorkflowStep::class, deps: ['fetch'])
        ->dispatch();

    expect($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::Dispatched->value);

    $drained = drainWorkflow($workflow);

    expect($drained->status)->toBe(WorkflowStatus::Completed)
        ->and($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::Completed->value)
        ->and($workflow->steps()->where('name', 'process')->value('output'))
        ->toBe(['count' => 3]);

    Bus::assertNotDispatched(RunWorkflowStep::class);
});

it('drains a workflow faked with a plain Bus::fake() to completion', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('pipeline')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1, 2]])
        ->cascade('process', ProcessWorkflowStep::class, deps: ['fetch'])
        ->dispatch();

    $drained = drainWorkflow($workflow);

    expect($drained->status)->toBe(WorkflowStatus::Completed)
        ->and($workflow->steps()->where('name', 'process')->value('output'))
        ->toBe(['count' => 2]);
});

it('drains a failing workflow through its compensation to a failed status', function (): void {
    Workflow::fake();

    $workflow = WorkflowDefinition::make('saga')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]], compensate: CompensateWorkflowStep::class)
        ->add('boom', FailingWorkflowStep::class, deps: ['fetch'])
        ->dispatch();

    $drained = drainWorkflow($workflow);

    expect($drained->status)->toBe(WorkflowStatus::Failed)
        ->and($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::Compensated->value)
        ->and($workflow->steps()->where('name', 'boom')->value('status'))
        ->toBe(WorkflowStatus::Failed->value)
        ->and(CompensateWorkflowStep::$released)->toBe([['items' => [1]]]);
});

it('is a no-op once the workflow already reached a finished status', function (): void {
    $workflow = WorkflowDefinition::make('sync')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->dispatch();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed);

    $drained = drainWorkflow($workflow);

    expect($drained->status)->toBe(WorkflowStatus::Completed);
});
