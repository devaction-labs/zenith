<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Instances\Actions;

use DevactionLabs\Zenith\Instances\LocalInstanceName;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\SupervisorCommands\ContinueWorking;
use RuntimeException;

final readonly class ContinueHorizon
{
    public function __construct(
        private MasterSupervisorRepository $masters,
        private HorizonCommandQueue $commands,
    ) {}

    public function handle(string $instance): void
    {
        $master = $this->findMaster($instance);

        if (
            ! is_object($master)
            || ($master->name ?? null) !== $instance
            || ! LocalInstanceName::matches($instance)
        ) {
            throw new RuntimeException('The requested Horizon instance is not active on this machine.');
        }

        $this->commands->push(
            MasterSupervisor::commandQueueFor($instance),
            ContinueWorking::class,
            [],
        );
    }

    private function findMaster(string $instance): mixed
    {
        return $this->masters->find($instance);
    }
}
