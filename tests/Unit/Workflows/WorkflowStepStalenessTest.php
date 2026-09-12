<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use DevactionLabs\Zenith\Workflows\WorkflowStep;
use DevactionLabs\Zenith\Workflows\WorkflowStepStaleness;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    migrateWorkflowTables();
});

function makeStalenessWorkflowStep(string $jobClass, WorkflowStatus $status, ?Carbon $updatedAt = null, ?Carbon $interruptedAt = null): WorkflowStep
{
    $workflow = Workflow::query()->create(['status' => WorkflowStatus::Running]);

    $step = new WorkflowStep([
        'workflow_id' => $workflow->id,
        'name' => 'step',
        'job_class' => $jobClass,
        'status' => $status->value,
        'job_uuid' => 'token',
    ]);
    $step->save();

    if ($updatedAt !== null) {
        $step->forceFill(['updated_at' => $updatedAt])->saveQuietly();
    }

    if ($interruptedAt !== null) {
        $step->forceFill(['interrupted_at' => $interruptedAt])->saveQuietly();
    }

    return $step->refresh();
}

it('is not stale when the step is not running or dispatched', function (): void {
    $step = makeStalenessWorkflowStep(ConfiguredWorkflowStep::class, WorkflowStatus::Pending, now()->subDay());

    expect((new WorkflowStepStaleness)->isStale($step))->toBeFalse();
});

it('is not stale while recently updated', function (): void {
    $step = makeStalenessWorkflowStep(ConfiguredWorkflowStep::class, WorkflowStatus::Running, now());

    expect((new WorkflowStepStaleness)->isStale($step))->toBeFalse();
});

it('is stale once older than the step class timeout', function (): void {
    $step = makeStalenessWorkflowStep(ConfiguredWorkflowStep::class, WorkflowStatus::Running, now()->subSeconds(31));

    expect((new WorkflowStepStaleness)->isStale($step))->toBeTrue();
});

it('is not stale before reaching the step class timeout', function (): void {
    $step = makeStalenessWorkflowStep(ConfiguredWorkflowStep::class, WorkflowStatus::Running, now()->subSeconds(29));

    expect((new WorkflowStepStaleness)->isStale($step))->toBeFalse();
});

it('falls back to the default timeout for a step class without a Timeout attribute', function (): void {
    $recent = makeStalenessWorkflowStep(ProcessWorkflowStep::class, WorkflowStatus::Dispatched, now()->subSeconds(59));
    $stale = makeStalenessWorkflowStep(ProcessWorkflowStep::class, WorkflowStatus::Dispatched, now()->subSeconds(61));

    expect((new WorkflowStepStaleness)->isStale($recent))->toBeFalse()
        ->and((new WorkflowStepStaleness)->isStale($stale))->toBeTrue();
});

it('is stale immediately once interrupted regardless of how recently it was updated', function (): void {
    $step = makeStalenessWorkflowStep(ProcessWorkflowStep::class, WorkflowStatus::Running, now(), now());

    expect((new WorkflowStepStaleness)->isStale($step))->toBeTrue();
});
