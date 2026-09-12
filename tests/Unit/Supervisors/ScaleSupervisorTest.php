<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Supervisors\Actions\ScaleSupervisor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\SupervisorCommands\Scale;

use function DevactionLabs\Zenith\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\dashboardThrowsFor;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

beforeEach(function (): void {
    MasterSupervisor::determineNameUsing(static fn (): string => 'local-host');
});

afterEach(function (): void {
    MasterSupervisor::$nameResolver = null;
});

describe('ScaleSupervisor', function (): void {
    it('queues a scale command for the exact active supervisor', function (): void {
        $supervisors = mockDashboardContract(SupervisorRepository::class);
        dashboardReturnsFor(
            $supervisors,
            'find',
            ['local-host-a1b2:supervisor-1'],
            (object) ['name' => 'local-host-a1b2:supervisor-1', 'master' => 'local-host-a1b2'],
        );
        $commands = mockDashboardContract(HorizonCommandQueue::class);
        dashboardReturnsFor(
            $commands,
            'push',
            ['local-host-a1b2:supervisor-1', Scale::class, ['scale' => 5]],
            null,
        );

        (new ScaleSupervisor($supervisors, $commands, app(ConfigRepository::class)))
            ->handle('local-host-a1b2:supervisor-1', 5);
    });

    it('clamps to the supervisor own configured bounds when present', function (): void {
        $supervisors = mockDashboardContract(SupervisorRepository::class);
        dashboardReturnsFor(
            $supervisors,
            'find',
            ['local-host-a1b2:supervisor-1'],
            (object) [
                'name' => 'local-host-a1b2:supervisor-1',
                'master' => 'local-host-a1b2',
                'options' => ['minProcesses' => 2, 'maxProcesses' => 8],
            ],
        );
        $commands = mockDashboardContract(HorizonCommandQueue::class);
        dashboardNeverReceives($commands, 'push');

        (new ScaleSupervisor($supervisors, $commands, app(ConfigRepository::class)))
            ->handle('local-host-a1b2:supervisor-1', 9);
    })->throws(InvalidArgumentException::class, 'The process count must be between 2 and 8.');

    it('falls back to the configured default bounds when the supervisor exposes none', function (): void {
        config()->set('zenith.supervisor_scale_bounds', ['min' => 1, 'max' => 3]);

        $supervisors = mockDashboardContract(SupervisorRepository::class);
        dashboardReturnsFor(
            $supervisors,
            'find',
            ['local-host-a1b2:supervisor-1'],
            (object) ['name' => 'local-host-a1b2:supervisor-1', 'master' => 'local-host-a1b2'],
        );
        $commands = mockDashboardContract(HorizonCommandQueue::class);
        dashboardNeverReceives($commands, 'push');

        (new ScaleSupervisor($supervisors, $commands, app(ConfigRepository::class)))
            ->handle('local-host-a1b2:supervisor-1', 4);
    })->throws(InvalidArgumentException::class, 'The process count must be between 1 and 3.');

    it('rejects an inactive supervisor', function (): void {
        $supervisors = mockDashboardContract(SupervisorRepository::class);
        dashboardReturnsFor($supervisors, 'find', ['missing-supervisor'], null);
        $commands = mockDashboardContract(HorizonCommandQueue::class);
        dashboardNeverReceives($commands, 'push');

        (new ScaleSupervisor($supervisors, $commands, app(ConfigRepository::class)))
            ->handle('missing-supervisor', 5);
    })->throws(RuntimeException::class, 'The requested Horizon supervisor is not active.');

    it('rejects a supervisor from a similarly named host sharing Redis', function (): void {
        $supervisors = mockDashboardContract(SupervisorRepository::class);
        dashboardReturnsFor(
            $supervisors,
            'find',
            ['local-host-2-a1b2:supervisor-1'],
            (object) ['name' => 'local-host-2-a1b2:supervisor-1', 'master' => 'local-host-2-a1b2'],
        );
        $commands = mockDashboardContract(HorizonCommandQueue::class);
        dashboardNeverReceives($commands, 'push');

        (new ScaleSupervisor($supervisors, $commands, app(ConfigRepository::class)))
            ->handle('local-host-2-a1b2:supervisor-1', 5);
    })->throws(RuntimeException::class, 'The requested Horizon supervisor is not active.');

    it('reports a failed command queue write', function (): void {
        $supervisors = mockDashboardContract(SupervisorRepository::class);
        dashboardReturnsFor(
            $supervisors,
            'find',
            ['local-host-a1b2:supervisor-1'],
            (object) ['name' => 'local-host-a1b2:supervisor-1', 'master' => 'local-host-a1b2'],
        );
        $commands = mockDashboardContract(HorizonCommandQueue::class);
        dashboardThrowsFor(
            $commands,
            'push',
            ['local-host-a1b2:supervisor-1', Scale::class, ['scale' => 5]],
            new RuntimeException('Redis unavailable.'),
        );

        (new ScaleSupervisor($supervisors, $commands, app(ConfigRepository::class)))
            ->handle('local-host-a1b2:supervisor-1', 5);
    })->throws(RuntimeException::class, 'Redis unavailable.');
});
