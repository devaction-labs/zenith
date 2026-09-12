<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Signals\Signal;
use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use RuntimeException;

beforeEach(function (): void {
    migrateWorkflowTables();
});

it('runs a fan-in workflow and cascades outputs into dependents', function (): void {
    $workflow = WorkflowDefinition::make('report')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1, 2, 3]])
        ->cascade('process', ProcessWorkflowStep::class, deps: ['fetch'])
        ->dispatch();

    $workflow->refresh();
    $process = $workflow->steps()->where('name', 'process')->first();

    expect($workflow->status)->toBe(WorkflowStatus::Completed)
        ->and($process?->status)->toBe(WorkflowStatus::Completed->value)
        ->and($process?->output)->toBe(['count' => 3]);
});

it('rejects a second unique workflow while one is unfinished', function (): void {
    WorkflowDefinition::make('nightly')
        ->unique()
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->dispatch();

    Workflow::query()->update(['status' => WorkflowStatus::Running->value, 'finished_at' => null]);

    expect(fn () => WorkflowDefinition::make('nightly')
        ->unique()
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [2]])
        ->dispatch())
        ->toThrow(RuntimeException::class, 'already running');
});

it('fails the workflow when a step throws', function (): void {
    $workflow = WorkflowDefinition::make('broken')
        ->add('boom', FailingWorkflowStep::class)
        ->add('after', ProcessWorkflowStep::class, deps: ['boom'])
        ->dispatch();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed)
        ->and($workflow->steps()->where('name', 'after')->value('status'))
        ->toBe(WorkflowStatus::Cancelled->value);
});

it('cancels unfinished steps', function (): void {
    $workflow = WorkflowDefinition::make('hold')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->dispatch();

    $workflow->update(['status' => WorkflowStatus::Running->value, 'finished_at' => null]);
    $workflow->steps()->update(['status' => WorkflowStatus::Pending->value, 'finished_at' => null]);

    $workflow->cancel();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Cancelled)
        ->and($workflow->steps()->where('status', WorkflowStatus::Cancelled->value)->count())->toBe(1);
});

it('retries from a failed step', function (): void {
    $workflow = WorkflowDefinition::make('retry')
        ->add('boom', FailingWorkflowStep::class)
        ->dispatch();

    $workflow->steps()->where('name', 'boom')->update([
        'job_class' => FetchWorkflowStep::class,
        'payload' => ['seed' => [9]],
    ]);

    $workflow->retryFrom('boom');

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($workflow->steps()->where('name', 'boom')->value('status'))
        ->toBe(WorkflowStatus::Completed->value);
});

it('runs a nested workflow before parent dependents', function (): void {
    $workflow = WorkflowDefinition::make('parent')
        ->addWorkflow(
            'child',
            WorkflowDefinition::make('inner')
                ->add('fetch', FetchWorkflowStep::class, ['seed' => [1, 2]])
                ->cascade('process', ProcessWorkflowStep::class, deps: ['fetch']),
        )
        ->cascade('after', ProcessWorkflowStep::class, deps: ['child'])
        ->dispatch();

    $child = Workflow::query()->where('parent_id', $workflow->id)->first();
    $after = $workflow->steps()->where('name', 'after')->first();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($child?->status)->toBe(WorkflowStatus::Completed)
        ->and($child?->parent_step)->toBe('child')
        ->and($after?->status)->toBe(WorkflowStatus::Completed->value)
        ->and($after?->output)->toBe(['count' => 2]);
});

it('fails the parent when a nested workflow fails', function (): void {
    $workflow = WorkflowDefinition::make('outer')
        ->addWorkflow(
            'child',
            WorkflowDefinition::make('inner')->add('boom', FailingWorkflowStep::class),
        )
        ->add('after', ProcessWorkflowStep::class, deps: ['child'])
        ->dispatch();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed)
        ->and($workflow->steps()->where('name', 'after')->value('status'))
        ->toBe(WorkflowStatus::Cancelled->value)
        ->and(Workflow::query()->where('parent_id', $workflow->id)->value('status'))
        ->toBe(WorkflowStatus::Failed->value);
});

it('cancels nested workflows with the parent', function (): void {
    $workflow = WorkflowDefinition::make('outer')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->dispatch();

    $child = WorkflowDefinition::make('inner')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->dispatch();

    $child->forceFill([
        'parent_id' => $workflow->id,
        'parent_step' => 'fetch',
        'status' => WorkflowStatus::Running,
        'finished_at' => null,
    ])->save();
    $child->steps()->update(['status' => WorkflowStatus::Pending->value, 'finished_at' => null]);

    $workflow->update(['status' => WorkflowStatus::Running->value, 'finished_at' => null]);
    $workflow->steps()->update(['status' => WorkflowStatus::Pending->value, 'finished_at' => null]);

    $workflow->cancel();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Cancelled)
        ->and($child->fresh()?->status)->toBe(WorkflowStatus::Cancelled);
});

it('runs compensations in reverse after a later step fails', function (): void {
    CompensateWorkflowStep::$released = [];

    $workflow = WorkflowDefinition::make('saga')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]], compensate: CompensateWorkflowStep::class)
        ->add('boom', FailingWorkflowStep::class, deps: ['fetch'])
        ->dispatch();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed)
        ->and($workflow->steps()->where('name', 'fetch')->value('status'))
        ->toBe(WorkflowStatus::Compensated->value)
        ->and(CompensateWorkflowStep::$released)->toBe([['items' => [1]]]);
});

it('redelivers a workflow step while it waits for a signal', function (): void {
    $workflow = WorkflowDefinition::make('gated')
        ->add('wait', AwaitingSignalWorkflowStep::class)
        ->dispatch();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Running)
        ->and($workflow->steps()->where('name', 'wait')->value('status'))
        ->toBe(WorkflowStatus::Running->value);

    Signal::send('workflow-approval', ['decision' => 'ok']);
    $workflow->retryFrom('wait');

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($workflow->steps()->where('name', 'wait')->value('output'))
        ->toBe(['decision' => 'ok']);
});
