<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Support;

use DevactionLabs\Zenith\Dashboard\DashboardPendingState;
use DevactionLabs\Zenith\Dashboard\HorizonStatus;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

final readonly class HorizonRuntime
{
    public function __construct(
        private MasterSupervisorRepository $masters,
        private ?WorkloadRepository $workload = null,
        private ?DashboardPendingState $pendingState = null,
        private ?WaitTimeCalculator $waitTimes = null,
    ) {}

    public function status(): HorizonStatus
    {
        try {
            $masters = $this->masters->all();

            return match (true) {
                $masters === [] => HorizonStatus::Inactive,
                collect($masters)->every(
                    static fn (object $master): bool => ($master->status ?? null) === 'paused',
                ) => HorizonStatus::Paused,
                default => HorizonStatus::Running,
            };
        } catch (Throwable $exception) {
            report($exception);

            return HorizonStatus::Unavailable;
        }
    }

    public function isProcessing(HorizonStatus $status): bool
    {
        if (
            $status !== HorizonStatus::Running
            || $this->workload === null
            || $this->pendingState === null
            || $this->waitTimes === null
        ) {
            return false;
        }

        try {
            $hasActiveProcesses = collect($this->workload->get())->contains(
                static fn (array $queue): bool => $queue['processes'] > 0,
            );

            if (! $hasActiveProcesses) {
                return false;
            }

            $waits = array_filter(
                $this->waitTimes->calculate(),
                static fn (mixed $wait, int|string $queue): bool => is_string($queue) && (is_int($wait) || is_float($wait)),
                ARRAY_FILTER_USE_BOTH,
            );
            $pending = $this->pendingState->forQueues($waits);

            return ($pending->reserved ?? 0) > 0 || ($pending->readyNow ?? 0) > 0;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
