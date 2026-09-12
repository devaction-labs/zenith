<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    migrateWorkflowTables();
    Bus::fake();
});

it('repairs stale workflow steps and reports how many it repaired', function (): void {
    $workflow = WorkflowDefinition::make('command-repair')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    Workflow::query()->findOrFail($workflow->id)
        ->steps()
        ->where('name', 'configured')
        ->update(['updated_at' => now()->subMinutes(5)]);

    Artisan::call('zenith:repair-workflows');

    expect(Artisan::output())->toContain('Repaired 1 stale workflow step.')
        ->and($workflow->steps()->where('name', 'configured')->value('status'))
        ->toBe(WorkflowStatus::Dispatched->value);
});

it('reports when there is nothing to repair', function (): void {
    WorkflowDefinition::make('command-no-repair')
        ->add('configured', ConfiguredWorkflowStep::class)
        ->dispatch();

    Artisan::call('zenith:repair-workflows');

    expect(Artisan::output())->toContain('Repaired 0 stale workflow steps.');
});
