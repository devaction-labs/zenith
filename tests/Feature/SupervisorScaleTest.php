<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\SupervisorCommands\Scale;
use Mockery\MockInterface;

use function DevactionLabs\Zenith\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\dashboardThrowsFor;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    MasterSupervisor::determineNameUsing(static fn (): string => 'local-host');
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
    MasterSupervisor::$nameResolver = null;
});

it('queues a scale command for the exact active supervisor', function (): void {
    $supervisors = bindScaleSupervisorRepository();
    dashboardReturnsFor(
        $supervisors,
        'find',
        ['local-host-a1b2:supervisor-1'],
        (object) ['name' => 'local-host-a1b2:supervisor-1'],
    );
    $commands = bindScaleCommandQueue();
    dashboardReturnsFor(
        $commands,
        'push',
        ['local-host-a1b2:supervisor-1', Scale::class, ['scale' => 6]],
        null,
    );

    postJson('/horizon/supervisors/local-host-a1b2%3Asupervisor-1/scale', ['processes' => 6])
        ->assertAccepted()
        ->assertJsonPath('message', 'Supervisor scale requested.');
});

it('rejects a scale value outside the supervisor configured bounds with a specific message', function (): void {
    $supervisors = bindScaleSupervisorRepository();
    dashboardReturnsFor(
        $supervisors,
        'find',
        ['local-host-a1b2:supervisor-1'],
        (object) [
            'name' => 'local-host-a1b2:supervisor-1',
            'options' => ['minProcesses' => 1, 'maxProcesses' => 4],
        ],
    );
    $commands = bindScaleCommandQueue();
    dashboardNeverReceives($commands, 'push');

    postJson('/horizon/supervisors/local-host-a1b2%3Asupervisor-1/scale', ['processes' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The process count must be between 1 and 4.');
});

it('rejects a missing supervisor', function (): void {
    $supervisors = bindScaleSupervisorRepository();
    dashboardReturnsFor($supervisors, 'find', ['missing-supervisor'], null);
    bindScaleCommandQueue();

    postJson('/horizon/supervisors/missing-supervisor/scale', ['processes' => 4])
        ->assertInternalServerError()
        ->assertJsonPath('message', 'Supervisor could not be scaled.');
});

it('reports a failed supervisor scale command queue write', function (): void {
    $supervisors = bindScaleSupervisorRepository();
    dashboardReturnsFor(
        $supervisors,
        'find',
        ['local-host-a1b2:supervisor-1'],
        (object) ['name' => 'local-host-a1b2:supervisor-1'],
    );
    $commands = bindScaleCommandQueue();
    dashboardThrowsFor(
        $commands,
        'push',
        ['local-host-a1b2:supervisor-1', Scale::class, ['scale' => 6]],
        new RuntimeException('Redis unavailable.'),
    );

    postJson('/horizon/supervisors/local-host-a1b2%3Asupervisor-1/scale', ['processes' => 6])
        ->assertInternalServerError()
        ->assertJsonPath('message', 'Supervisor could not be scaled.');
});

it('rejects a non-numeric process count', function (): void {
    postJson('/horizon/supervisors/local-host-a1b2%3Asupervisor-1/scale', ['processes' => 'many'])
        ->assertUnprocessable();
});

it('forbids supervisor scaling when the manageInstances gate is denied', function (): void {
    Gate::define('zenith.manageInstances', static fn (): bool => false);

    postJson('/horizon/supervisors/local-host-a1b2%3Asupervisor-1/scale', ['processes' => 4])
        ->assertForbidden();
});

it('honors Horizon authorization', function (): void {
    Horizon::auth(static fn (): bool => false);

    postJson('/horizon/supervisors/local-host-a1b2%3Asupervisor-1/scale', ['processes' => 4])
        ->assertForbidden();
});

function bindScaleSupervisorRepository(): SupervisorRepository&MockInterface
{
    $repository = mockDashboardContract(SupervisorRepository::class);
    app()->instance(SupervisorRepository::class, $repository);

    return $repository;
}

function bindScaleCommandQueue(): HorizonCommandQueue&MockInterface
{
    $commands = mockDashboardContract(HorizonCommandQueue::class);
    app()->instance(HorizonCommandQueue::class, $commands);

    return $commands;
}
