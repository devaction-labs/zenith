<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Workflows\Workflow;
use DevactionLabs\Zenith\Workflows\WorkflowDefinition;
use DevactionLabs\Zenith\Workflows\WorkflowStatus;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Inertia\Testing\AssertableInertia;
use Laravel\Horizon\Horizon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    migrateWorkflowTables();
});

it('lists workflows', function (): void {
    WorkflowDefinition::make('report')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->dispatch();

    get('/horizon/workflows')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Workflows/Index')
            ->where('meta.activeNavigation', 'workflows')
            ->has('workflows', 1)
            ->where('workflows.0.name', 'report')
            ->where('workflows.0.status', 'completed'));
});

it('shows workflow steps and dependencies', function (): void {
    $workflow = WorkflowDefinition::make('graph')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1, 2]])
        ->cascade('process', ProcessWorkflowStep::class, deps: ['fetch'])
        ->dispatch();

    get("/horizon/workflows/{$workflow->id}")
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Workflows/Show')
            ->where('workflow.name', 'graph')
            ->has('workflow.steps', 2)
            ->where('workflow.steps.1.deps.0', 'fetch')
            ->where('workflow.parentId', null)
            ->where('workflow.children', []));
});

it('lists only top-level workflows and shows nested children on detail', function (): void {
    $workflow = WorkflowDefinition::make('parent')
        ->addWorkflow(
            'child',
            WorkflowDefinition::make('inner')
                ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]]),
        )
        ->dispatch();

    get('/horizon/workflows')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Workflows/Index')
            ->has('workflows', 1)
            ->where('workflows.0.name', 'parent'));

    get("/horizon/workflows/{$workflow->id}")
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Workflows/Show')
            ->where('workflow.steps.0.nested', true)
            ->has('workflow.children', 1)
            ->where('workflow.children.0.name', 'inner'));
});

it('cancels a running workflow from the dashboard', function (): void {
    $workflow = WorkflowDefinition::make('open')
        ->add('fetch', FetchWorkflowStep::class, ['seed' => [1]])
        ->dispatch();

    $workflow->update(['status' => WorkflowStatus::Running->value, 'finished_at' => null]);
    $workflow->steps()->update(['status' => WorkflowStatus::Pending->value, 'finished_at' => null]);

    post("/horizon/workflows/{$workflow->id}/cancel")->assertRedirect();

    expect(Workflow::query()->find($workflow->id)?->status)->toBe(WorkflowStatus::Cancelled);
});
