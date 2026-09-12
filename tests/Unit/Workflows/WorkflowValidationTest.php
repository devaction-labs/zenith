<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStep;

beforeEach(function (): void {
    migrateWorkflowTables();
});

it('rejects invalid step dependencies without persisting anything', function (WorkflowDefinition $definition, string $message): void {
    expect(fn (): Workflow => $definition->dispatch())
        ->toThrow(InvalidArgumentException::class, $message)
        ->and(Workflow::query()->count())->toBe(0)
        ->and(WorkflowStep::query()->count())->toBe(0);
})->with([
    'unknown dependency' => [
        fn (): WorkflowDefinition => WorkflowDefinition::make('unknown')
            ->add('fetch', FetchWorkflowStep::class)
            ->add('process', ProcessWorkflowStep::class, deps: ['fetch', 'missing']),
        'Workflow step [process] depends on unknown step [missing].',
    ],
    'self dependency' => [
        fn (): WorkflowDefinition => WorkflowDefinition::make('self')
            ->add('fetch', FetchWorkflowStep::class)
            ->add('loop', ProcessWorkflowStep::class, deps: ['fetch', 'loop']),
        'Workflow step [loop] cannot depend on itself.',
    ],
    'multi-step cycle' => [
        fn (): WorkflowDefinition => WorkflowDefinition::make('cycle')
            ->add('a', FetchWorkflowStep::class, deps: ['c'])
            ->add('b', ProcessWorkflowStep::class, deps: ['a'])
            ->add('c', ProcessWorkflowStep::class, deps: ['b']),
        'Workflow steps form a dependency cycle: [a -> c -> b -> a].',
    ],
]);

it('accepts dependencies declared before the step they point to', function (): void {
    $workflow = WorkflowDefinition::make('forward')
        ->cascade('process', ProcessWorkflowStep::class, deps: ['fetch'])
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1, 2]])
        ->dispatch();

    expect($workflow->steps()->where('name', 'process')->value('output'))->toBe(['count' => 2]);
});
