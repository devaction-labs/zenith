<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

use DevactionLabs\Zenith\Queues\Data\QueueBypassWarningData;

/**
 * Surfaces the Laravel 13 queue-connection drivers that can bypass Horizon
 * entirely (failover, deferred, background), combined with any recent
 * QueueFailedOver activity, so the dashboard and queues page can warn an
 * operator that some jobs are invisible to Zenith.
 */
final readonly class QueueBypassWarning
{
    /** @var list<string> */
    private const array BYPASS_PRONE_DRIVERS = ['failover', 'deferred', 'background'];

    public function __construct(
        private QueueFailoverActivity $failovers,
    ) {}

    public function summary(): QueueBypassWarningData
    {
        $recent = $this->failovers->recent();
        $connections = array_values(array_unique(array_filter(
            array_column($recent, 'connection'),
            is_string(...),
        )));

        return new QueueBypassWarningData(
            hasRecentFailovers: $recent !== [],
            recentFailoverCount: count($recent),
            recentFailoverWindowMinutes: $this->failovers->windowMinutes(),
            recentFailoverConnections: $connections,
            bypassProneConnections: $this->bypassProneConnections(),
        );
    }

    /**
     * @return list<string>
     */
    private function bypassProneConnections(): array
    {
        $connections = config('queue.connections');

        if (! is_array($connections)) {
            return [];
        }

        $names = [];

        foreach ($connections as $name => $connection) {
            if (! is_string($name) || ! is_array($connection)) {
                continue;
            }

            $driver = $connection['driver'] ?? null;

            if (is_string($driver) && in_array($driver, self::BYPASS_PRONE_DRIVERS, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
