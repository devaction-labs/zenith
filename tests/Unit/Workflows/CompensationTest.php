<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;

beforeEach(function (): void {
    migrateWorkflowTables();
    CompensateWorkflowStep::$released = [];
    FailingCompensateWorkflowStep::$shouldFail = true;
});

it('runs compensations in reverse completion order', function (): void {
    $workflow = WorkflowDefinition::make('saga')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]], compensate: CompensateWorkflowStep::class)
        ->cascade('process', ProcessWorkflowStep::class, deps: ['fetch'], compensate: CompensateWorkflowStep::class)
        ->add('boom', FailingWorkflowStep::class, deps: ['process'])
        ->dispatch();

    expect(CompensateWorkflowStep::$released)->toBe([['count' => 1], ['items' => [1]]])
        ->and($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed)
        ->and($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::Compensated->value)
        ->and($workflow->steps()->where('name', 'process')->value('status'))
        ->toBe(WorkflowStatus::Compensated->value)
        ->and($workflow->steps()->where('name', 'boom')->value('status'))
        ->toBe(WorkflowStatus::Failed->value);
});

it('compensates only the steps that completed', function (): void {
    $workflow = WorkflowDefinition::make('partial')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]], compensate: CompensateWorkflowStep::class)
        ->add('boom', FailingWorkflowStep::class, deps: ['fetch'])
        ->add('later', ProcessWorkflowStep::class, deps: ['boom'], compensate: CompensateWorkflowStep::class)
        ->dispatch();

    expect(CompensateWorkflowStep::$released)->toBe([['items' => [1]]])
        ->and($workflow->steps()->where('name', 'later')->value('status'))
        ->toBe(WorkflowStatus::Cancelled->value);
});

it('leaves a workflow without compensations untouched', function (): void {
    $workflow = WorkflowDefinition::make('plain')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->add('boom', FailingWorkflowStep::class, deps: ['fetch'])
        ->dispatch();

    expect(CompensateWorkflowStep::$released)->toBe([])
        ->and($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::Completed->value);
});

it('records a failing compensation and retries it from the dashboard', function (): void {
    $workflow = WorkflowDefinition::make('saga')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]], compensate: FailingCompensateWorkflowStep::class)
        ->add('boom', FailingWorkflowStep::class, deps: ['fetch'])
        ->dispatch();

    expect($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::CompensationFailed->value)
        ->and($workflow->steps()->where('name', 'fetch')->value('error'))
        ->toBe('compensation failed')
        ->and($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed);

    FailingCompensateWorkflowStep::$shouldFail = false;
    $workflow->retryFrom('fetch');

    expect($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::Compensated->value)
        ->and($workflow->steps()->where('name', 'fetch')->value('error'))->toBeNull()
        ->and($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed);
});

it('re-runs compensated steps when the workflow is retried', function (): void {
    $workflow = WorkflowDefinition::make('saga')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]], compensate: CompensateWorkflowStep::class)
        ->add('boom', FailingWorkflowStep::class, deps: ['fetch'])
        ->dispatch();

    expect($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::Compensated->value);

    $workflow->steps()->where('name', 'boom')->update(['job_class' => ProcessWorkflowStep::class]);
    $workflow->retryFrom('boom');

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::Completed->value)
        ->and($workflow->steps()->where('name', 'fetch')->value('attempts'))->toBe(2);
});

it('compensates the completed steps of a failed nested workflow', function (): void {
    $workflow = WorkflowDefinition::make('outer')
        ->addWorkflow(
            'child',
            WorkflowDefinition::make('inner')
                ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]], compensate: CompensateWorkflowStep::class)
                ->add('boom', FailingWorkflowStep::class, deps: ['fetch']),
        )
        ->dispatch();

    expect(CompensateWorkflowStep::$released)->toBe([['items' => [1]]])
        ->and($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed);
});
