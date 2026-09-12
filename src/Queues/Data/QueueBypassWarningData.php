<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues\Data;

use Spatie\LaravelData\Data;

final class QueueBypassWarningData extends Data
{
    /**
     * @param  list<string>  $recentFailoverConnections
     * @param  list<string>  $bypassProneConnections
     */
    public function __construct(
        public readonly bool $hasRecentFailovers,
        public readonly int $recentFailoverCount,
        public readonly int $recentFailoverWindowMinutes,
        public readonly array $recentFailoverConnections,
        public readonly array $bypassProneConnections,
    ) {}

    public static function none(): self
    {
        return new self(
            hasRecentFailovers: false,
            recentFailoverCount: 0,
            recentFailoverWindowMinutes: 0,
            recentFailoverConnections: [],
            bypassProneConnections: [],
        );
    }
}
