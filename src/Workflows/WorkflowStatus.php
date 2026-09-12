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

    /**
     * @return list<string>
     */
    public static function finishedValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->finished()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function activeStepValues(): array
    {
        return [
            self::Pending->value,
            self::Dispatched->value,
            self::Running->value,
        ];
    }

    public function finished(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
