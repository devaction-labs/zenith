<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Testing\PendingCommand;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;

use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\artisan;

it('prints Prometheus text-format samples for queue depth, wait time, and throughput', function (): void {
    $supervisors = mockDashboardContract(SupervisorRepository::class);
    dashboardReturns($supervisors, 'all', [
        (object) ['processes' => ['redis:default' => 2]],
    ]);

    $redis = mockDashboardContract(Queue::class);
    dashboardReturnsFor($redis, 'readyNow', ['default'], 5);
    dashboardReturnsFor($redis, 'reservedSize', ['default'], 2);
    dashboardReturnsFor($redis, 'delayedSize', ['default'], 1);
    dashboardReturnsFor($redis, 'creationTimeOfOldestPendingJob', ['default'], null);

    $queues = mockDashboardContract(QueueFactory::class);
    dashboardReturnsFor($queues, 'connection', ['redis'], $redis);

    $waitTimes = mockDashboardContract(WaitTimeCalculator::class);
    dashboardReturnsFor($waitTimes, 'calculateTimeToClear', ['redis', 'default', 2], 3.5);

    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturns($metrics, 'runtimeForQueue', 1000);
    dashboardReturns($metrics, 'throughputForQueue', 42);

    app()->instance(SupervisorRepository::class, $supervisors);
    app()->instance(QueueFactory::class, $queues);
    app()->instance(WaitTimeCalculator::class, $waitTimes);
    app()->instance(MetricsRepository::class, $metrics);

    $command = artisan('zenith:export-metrics');

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('The export metrics command did not return a pending command.');
    }

    $command
        ->expectsOutputToContain('# TYPE zenith_queue_depth gauge')
        ->expectsOutputToContain('zenith_queue_depth{queue="default"} 8')
        ->expectsOutputToContain('# TYPE zenith_queue_wait_seconds gauge')
        ->expectsOutputToContain('zenith_queue_wait_seconds{queue="default"} 3.5')
        ->expectsOutputToContain('# TYPE zenith_queue_throughput_per_minute gauge')
        ->expectsOutputToContain('zenith_queue_throughput_per_minute{queue="default"} 42')
        ->assertSuccessful()
        ->execute();
});
