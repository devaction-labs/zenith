<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\AdvanceWorkflow;
use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use DevactionLabs\Zenith\Workflows\WorkflowStep;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    migrateWorkflowTables();
});

it('rejects an invalid nested workflow without persisting anything', function (): void {
    expect(fn (): Workflow => WorkflowDefinition::make('outer')
        ->addWorkflow(
            'child',
            WorkflowDefinition::make('inner')->add('process', ProcessWorkflowStep::class, deps: ['missing']),
        )
        ->dispatch())
        ->toThrow(InvalidArgumentException::class, 'Workflow step [process] depends on unknown step [missing].')
        ->and(Workflow::query()->count())->toBe(0)
        ->and(WorkflowStep::query()->count())->toBe(0);
});

it('starts a nested workflow only once its dependencies complete', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('outer')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->addWorkflow(
            'child',
            WorkflowDefinition::make('inner')->add('process', ProcessWorkflowStep::class),
            deps: ['fetch'],
        )
        ->dispatch();

    $child = Workflow::query()->where('parent_id', $workflow->id)->firstOrFail();

    expect($child->status)->toBe(WorkflowStatus::Pending)
        ->and($child->parent_step)->toBe('child')
        ->and($child->steps()->value('status'))->toBe(WorkflowStatus::Pending->value);

    dispatchedWorkflowStep('fetch')->handle(app(AdvanceWorkflow::class));

    expect($child->fresh()?->status)->toBe(WorkflowStatus::Running)
        ->and($workflow->steps()->where('name', 'child')->value('status'))->toBe(WorkflowStatus::Running->value)
        ->and(Bus::dispatched(
            RunWorkflowStep::class,
            static fn (RunWorkflowStep $job): bool => $job->workflowId === $child->id,
        ))->toHaveCount(1);
});

it('fails the parent step when its nested workflow is cancelled', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('outer')
        ->addWorkflow('child', WorkflowDefinition::make('inner')->add('fetch', FetchWorkflowStep::class))
        ->add('after', ProcessWorkflowStep::class, deps: ['child'])
        ->dispatch();

    Workflow::query()->where('parent_id', $workflow->id)->firstOrFail()->cancel();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed)
        ->and($workflow->steps()->where('name', 'child')->value('status'))->toBe(WorkflowStatus::Failed->value)
        ->and($workflow->steps()->where('name', 'child')->value('error'))
        ->toBe('Nested workflow [inner] was cancelled.')
        ->and($workflow->steps()->where('name', 'after')->value('status'))->toBe(WorkflowStatus::Cancelled->value);
});

it('resumes a failed nested workflow from its failed step when the parent retries', function (): void {
    $workflow = WorkflowDefinition::make('outer')
        ->addWorkflow(
            'child',
            WorkflowDefinition::make('inner')
                ->add('fetch', FetchWorkflowStep::class, ['seed' => [1, 2, 3]])
                ->add('boom', FailingWorkflowStep::class, deps: ['fetch']),
        )
        ->cascade('after', ProcessWorkflowStep::class, deps: ['child'])
        ->dispatch();

    $child = Workflow::query()->where('parent_id', $workflow->id)->firstOrFail();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed)
        ->and($child->status)->toBe(WorkflowStatus::Failed);

    $child->steps()->where('name', 'boom')->update(['job_class' => ProcessWorkflowStep::class]);
    $workflow->retryFrom('child');

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($child->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($child->steps()->where('name', 'fetch')->value('attempts'))->toBe(1)
        ->and($workflow->steps()->where('name', 'after')->value('output'))->toBe(['count' => 3]);
});
