<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    migrateWorkflowTables();
});

it('records a real workflow without dispatching its steps once faked', function (): void {
    Workflow::fake();

    $workflow = WorkflowDefinition::make('onboarding')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    expect($workflow)->toBeInstanceOf(Workflow::class)
        ->and($workflow->steps()->where('name', 'configured')->value('status'))
        ->toBe(WorkflowStatus::Dispatched->value);

    Bus::assertNotDispatched(RunWorkflowStep::class);
});

it('asserts a workflow matching a name was dispatched', function (): void {
    Workflow::fake();

    WorkflowDefinition::make('onboarding')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    Workflow::assertDispatched('onboarding');
});

it('asserts any workflow was dispatched when no name is given', function (): void {
    Workflow::fake();

    WorkflowDefinition::make('onboarding')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    Workflow::assertDispatched();
});

it('asserts a workflow matching a predicate was dispatched', function (): void {
    Workflow::fake();

    WorkflowDefinition::make('onboarding')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    Workflow::assertDispatched(
        fn (WorkflowDefinition $definition): bool => $definition->name() === 'onboarding' && count($definition->steps()) === 1,
    );
});

it('fails the assertion when no matching workflow was dispatched', function (): void {
    Workflow::fake();

    WorkflowDefinition::make('onboarding')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    expect(fn () => Workflow::assertDispatched('offboarding'))->toThrow(AssertionFailedError::class);
});

it('gives every fake() call a clean slate', function (): void {
    Workflow::fake();

    WorkflowDefinition::make('onboarding')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    Workflow::fake();

    expect(fn () => Workflow::assertDispatched('onboarding'))->toThrow(AssertionFailedError::class);
});
