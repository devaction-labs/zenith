<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Workflows;

enum WorkflowStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Dispatched = 'dispatched';

    public function finished(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
