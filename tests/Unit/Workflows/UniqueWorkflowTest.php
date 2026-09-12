<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowAlreadyRunning;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use DevactionLabs\Zenith\Workflows\WorkflowStep;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    migrateWorkflowTables();
});

it('dispatches a unique workflow again once the previous run finished', function (Workflow $previous, WorkflowStatus $status): void {
    expect($previous->fresh()?->status)->toBe($status);

    $next = WorkflowDefinition::make('nightly')
        ->unique()
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [2]])
        ->dispatch();

    expect($next->id)->not->toBe($previous->id)
        ->and($next->unique_key)->not->toBeNull()
        ->and($previous->fresh()?->unique_key)->toBeNull()
        ->and(Workflow::query()->count())->toBe(2);
})->with([
    'completed' => [
        fn (): Workflow => WorkflowDefinition::make('nightly')
            ->unique()
            ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
            ->dispatch(),
        WorkflowStatus::Completed,
    ],
    'failed' => [
        fn (): Workflow => WorkflowDefinition::make('nightly')
            ->unique()
            ->add('boom', FailingWorkflowStep::class)
            ->dispatch(),
        WorkflowStatus::Failed,
    ],
    'cancelled' => [
        function (): Workflow {
            Bus::fake();

            $workflow = WorkflowDefinition::make('nightly')
                ->unique()
                ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
                ->dispatch();

            $workflow->cancel();

            return $workflow;
        },
        WorkflowStatus::Cancelled,
    ],
]);

it('lets the database admit only one of two overlapping unique dispatches', function (): void {
    Bus::fake();

    $running = WorkflowDefinition::make('nightly')
        ->unique()
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->dispatch();

    $rejection = null;

    try {
        WorkflowDefinition::make('nightly')
            ->unique()
            ->add('fetch', FetchWorkflowStep::class, ['seed' => [2]])
            ->dispatch();
    } catch (WorkflowAlreadyRunning $exception) {
        $rejection = $exception;
    }

    expect($rejection)->toBeInstanceOf(WorkflowAlreadyRunning::class)
        ->and($rejection?->getMessage())->toBe('A unique workflow [nightly] is already running.')
        ->and($rejection?->getPrevious())->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and(Workflow::query()->where('status', WorkflowStatus::Running->value)->pluck('id')->all())
        ->toBe([$running->id])
        ->and(WorkflowStep::query()->count())->toBe(1);
});

it('keeps non-unique workflows with the same name independent', function (): void {
    Bus::fake();

    WorkflowDefinition::make('report')->add('fetch', FetchWorkflowStep::class)->dispatch();
    WorkflowDefinition::make('report')->add('fetch', FetchWorkflowStep::class)->dispatch();

    expect(Workflow::query()->where('status', WorkflowStatus::Running->value)->count())->toBe(2);
});
