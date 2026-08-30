<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Authorization;

enum HorizonAbility: string
{
    case PauseQueues = 'pauseQueues';
    case ClearQueues = 'clearQueues';
    case RetryJobs = 'retryJobs';
    case CancelJobs = 'cancelJobs';
    case ManageInstances = 'manageInstances';
    case ManageMonitoring = 'manageMonitoring';
    case ManageBatches = 'manageBatches';

    public function gate(): string
    {
        return 'horizon-new-dawn.'.$this->value;
    }
}
