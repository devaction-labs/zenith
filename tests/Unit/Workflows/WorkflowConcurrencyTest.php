<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\AdvanceWorkflow;
use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateWorkflowTables();
    CountingWorkflowStep::$runs = [];
});

it('runs every step of a diamond workflow exactly once', function (): void {
    $workflow = WorkflowDefinition::make('diamond')
        ->add('left', CountingWorkflowStep::class, ['label' => 'left'])
        ->add('right', CountingWorkflowStep::class, ['label' => 'right'])
        ->add('join', CountingWorkflowStep::class, ['label' => 'join'], deps: ['left', 'right'])
        ->dispatch();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and(CountingWorkflowStep::$runs)->toBe(['left', 'right', 'join'])
        ->and($workflow->steps()->pluck('status', 'name')->all())->toBe([
            'left' => WorkflowStatus::Completed->value,
            'right' => WorkflowStatus::Completed->value,
            'join' => WorkflowStatus::Completed->value,
        ]);
});

it('dispatches a fan-in step once when its dependencies finish together', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('fan-in')
        ->add('left', FetchWorkflowStep::class)
        ->add('right', FetchWorkflowStep::class)
        ->add('join', ProcessWorkflowStep::class, deps: ['left', 'right'])
        ->dispatch();

    $workflow->steps()
        ->whereIn('name', ['left', 'right'])
        ->update(['status' => WorkflowStatus::Completed->value]);

    $advance = app(AdvanceWorkflow::class);
    $interleaved = false;

    DB::connection()->beforeExecuting(
        static function (string $query) use (&$interleaved, $advance, $workflow): void {
            if ($interleaved || ! str_starts_with($query, 'update "zenith_workflow_steps"')) {
                return;
            }

            $interleaved = true;
            $advance->dispatchReady(Workflow::query()->findOrFail($workflow->id));
        },
    );

    $advance->dispatchReady(Workflow::query()->findOrFail($workflow->id));

    expect($interleaved)->toBeTrue()
        ->and(Bus::dispatched(
            RunWorkflowStep::class,
            static fn (RunWorkflowStep $job): bool => $job->stepName === 'join',
        ))->toHaveCount(1);
});

it('ignores a redelivered job for a step that already completed', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('once')
        ->add('first', CountingWorkflowStep::class, ['label' => 'first'])
        ->add('second', CountingWorkflowStep::class, ['label' => 'second'], deps: ['first'])
        ->dispatch();

    $job = dispatchedWorkflowStep('first');
    $job->handle(app(AdvanceWorkflow::class));
    $job->handle(app(AdvanceWorkflow::class));

    expect(CountingWorkflowStep::$runs)->toBe(['first'])
        ->and($workflow->steps()->where('name', 'first')->value('attempts'))->toBe(1)
        ->and($workflow->steps()->where('name', 'second')->value('status'))
        ->toBe(WorkflowStatus::Dispatched->value)
        ->and(Bus::dispatched(
            RunWorkflowStep::class,
            static fn (RunWorkflowStep $job): bool => $job->stepName === 'second',
        ))->toHaveCount(1);
});

it('ignores a stale job once a retry claimed the step for a newer job', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('stale')
        ->add('first', CountingWorkflowStep::class, ['label' => 'first'])
        ->add('second', CountingWorkflowStep::class, ['label' => 'second'], deps: ['first'])
        ->dispatch();

    $stale = dispatchedWorkflowStep('first');
    $workflow->retryFrom('first');
    $fresh = dispatchedWorkflowStep('first');

    $stale->handle(app(AdvanceWorkflow::class));
    $fresh->handle(app(AdvanceWorkflow::class));

    expect($fresh->token)->not->toBe($stale->token)
        ->and(CountingWorkflowStep::$runs)->toBe(['first'])
        ->and($workflow->steps()->where('name', 'first')->value('status'))
        ->toBe(WorkflowStatus::Completed->value);
});
