<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Authorization;

enum HorizonAbility: string
{
    case PauseQueues = 'pauseQueues';
    case ClearQueues = 'clearQueues';
    case RetryJobs = 'retryJobs';
    case CancelJobs = 'cancelJobs';
    case ManageInstances = 'manageInstances';
    case ManageMonitoring = 'manageMonitoring';
    case ManageBatches = 'manageBatches';
    case ManageSchedule = 'manageSchedule';
    case ManageWorkflows = 'manageWorkflows';

    public function gate(): string
    {
        return 'zenith.'.$this->value;
    }
}
