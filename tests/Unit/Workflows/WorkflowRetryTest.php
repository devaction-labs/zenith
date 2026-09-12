<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\AdvanceWorkflow;
use DevactionLabs\Zenith\Workflows\RunWorkflowStep;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateWorkflowTables();
    FlakyWorkflowStep::$failuresLeft = 0;
    WaitingWorkflowStep::$signalled = false;
});

it('applies the queue attributes of the step class to its job', function (): void {
    Bus::fake();

    WorkflowDefinition::make('configured')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    $job = dispatchedWorkflowStep('configured');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 10])
        ->and($job->timeout)->toBe(30)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->maxExceptions)->toBe(2)
        ->and($job->queue)->toBe('workflows')
        ->and($job->connection)->toBe('database');
});

it('keeps the queue defaults for a step class without attributes', function (): void {
    Bus::fake();

    WorkflowDefinition::make('plain')
        ->add('fetch', FetchWorkflowStep::class)
        ->dispatch();

    $job = dispatchedWorkflowStep('fetch');

    expect($job->tries)->toBeNull()
        ->and($job->backoff)->toBeNull()
        ->and($job->timeout)->toBeNull()
        ->and($job->failOnTimeout)->toBeFalse()
        ->and($job->maxExceptions)->toBeNull()
        ->and($job->queue)->toBeNull()
        ->and($job->connection)->toBeNull();
});

it('records a failed attempt and rethrows so the worker can retry the step', function (): void {
    Bus::fake();
    FlakyWorkflowStep::$failuresLeft = 1;

    $workflow = WorkflowDefinition::make('flaky')
        ->add('flaky', FlakyWorkflowStep::class)
        ->dispatch();

    $job = dispatchedWorkflowStep('flaky')->withFakeQueueInteractions();

    expect(function () use ($job): void {
        $job->handle(app(AdvanceWorkflow::class));
    })->toThrow(RuntimeException::class, 'flaky failure');

    $step = $workflow->steps()->where('name', 'flaky')->firstOrFail();

    expect($step->status)->toBe(WorkflowStatus::Retrying->value)
        ->and($step->attempts)->toBe(1)
        ->and($step->error)->toBe('flaky failure')
        ->and($workflow->fresh()?->status)->toBe(WorkflowStatus::Running);

    $job->assertNotFailed();
    $job->handle(app(AdvanceWorkflow::class));

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($workflow->steps()->where('name', 'flaky')->value('attempts'))->toBe(2)
        ->and($workflow->steps()->where('name', 'flaky')->value('error'))->toBeNull();
});

it('retries a failing step on a queue worker until it succeeds', function (): void {
    useDatabaseWorkflowQueue();
    FlakyWorkflowStep::$failuresLeft = 2;

    $workflow = WorkflowDefinition::make('flaky')
        ->add('flaky', FlakyWorkflowStep::class)
        ->add('after', ProcessWorkflowStep::class, deps: ['flaky'])
        ->dispatch();

    workWorkflowQueue();

    $step = $workflow->steps()->where('name', 'flaky')->firstOrFail();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($step->status)->toBe(WorkflowStatus::Completed->value)
        ->and($step->attempts)->toBe(3)
        ->and($step->error)->toBeNull()
        ->and($workflow->steps()->where('name', 'after')->value('status'))
        ->toBe(WorkflowStatus::Completed->value)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('fails the workflow once a step exhausts its attempts on a queue worker', function (): void {
    useDatabaseWorkflowQueue();

    $workflow = WorkflowDefinition::make('exhausted')
        ->add('flaky', ExhaustingWorkflowStep::class)
        ->add('after', ProcessWorkflowStep::class, deps: ['flaky'])
        ->dispatch();

    workWorkflowQueue();

    $step = $workflow->steps()->where('name', 'flaky')->firstOrFail();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed)
        ->and($step->status)->toBe(WorkflowStatus::Failed->value)
        ->and($step->attempts)->toBe(2)
        ->and($step->error)->toBe('still failing')
        ->and($workflow->steps()->where('name', 'after')->value('status'))
        ->toBe(WorkflowStatus::Cancelled->value)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('fails a retryable step at once on the sync queue, which cannot redeliver it', function (): void {
    FlakyWorkflowStep::$failuresLeft = 1;

    $workflow = WorkflowDefinition::make('sync')
        ->add('flaky', FlakyWorkflowStep::class)
        ->dispatch();

    $step = $workflow->steps()->where('name', 'flaky')->firstOrFail();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Failed)
        ->and($step->status)->toBe(WorkflowStatus::Failed->value)
        ->and($step->attempts)->toBe(1)
        ->and($step->error)->toBe('flaky failure');
});

it('keeps a step running while it waits for a signal and resumes it on retry', function (): void {
    $workflow = WorkflowDefinition::make('gated')
        ->add('wait', WaitingWorkflowStep::class)
        ->add('after', ProcessWorkflowStep::class, deps: ['wait'])
        ->dispatch();

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Running)
        ->and($workflow->steps()->where('name', 'wait')->value('status'))->toBe(WorkflowStatus::Running->value)
        ->and($workflow->steps()->where('name', 'wait')->value('error'))->toBeNull()
        ->and($workflow->steps()->where('name', 'after')->value('status'))->toBe(WorkflowStatus::Pending->value);

    WaitingWorkflowStep::$signalled = true;
    $workflow->retryFrom('wait');

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($workflow->steps()->where('name', 'wait')->value('output'))->toBe(['approved' => true]);
});

it('redelivers a waiting step with a fresh delayed job instead of burning an attempt', function (): void {
    Bus::fake();

    $workflow = WorkflowDefinition::make('gated')
        ->add('wait', WaitingWorkflowStep::class)
        ->dispatch();

    $job = dispatchedWorkflowStep('wait')->withFakeQueueInteractions();
    $job->handle(app(AdvanceWorkflow::class));

    $redelivery = dispatchedWorkflowStep('wait');

    expect($redelivery)->not->toBe($job)
        ->and($redelivery->token)->toBe($job->token)
        ->and($redelivery->delay)->toBe(RunWorkflowStep::SIGNAL_RETRY_SECONDS)
        ->and($workflow->steps()->where('name', 'wait')->value('status'))->toBe(WorkflowStatus::Running->value);

    $job->assertNotFailed()->assertNotReleased();

    WaitingWorkflowStep::$signalled = true;
    $redelivery->withFakeQueueInteractions()->handle(app(AdvanceWorkflow::class));

    expect($workflow->fresh()?->status)->toBe(WorkflowStatus::Completed)
        ->and($workflow->steps()->where('name', 'wait')->value('attempts'))->toBe(2);
});
