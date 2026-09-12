<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\Zenith\Batches\BatchesData;
use DevactionLabs\Zenith\Batches\BatchJobsData;
use DevactionLabs\Zenith\FailedJobs\FailedJobRetryEligibility;
use DevactionLabs\Zenith\FailedJobs\FailedJobsData;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Metrics\SnapshotJobsPerMinute;
use DevactionLabs\Zenith\Queues\Data\QueuePauseTargetData;
use DevactionLabs\Zenith\Queues\Data\QueueRetainedJobsData;
use DevactionLabs\Zenith\Queues\Data\QueueRowData;
use DevactionLabs\Zenith\Queues\Data\QueueWaitThresholdData;
use DevactionLabs\Zenith\Queues\Data\QueueWaitThresholdTargetData;
use DevactionLabs\Zenith\Queues\QueueBatchesData;
use DevactionLabs\Zenith\Queues\QueueJobsData;
use DevactionLabs\Zenith\Queues\QueuePauseMetadata;
use DevactionLabs\Zenith\Queues\QueuePauseStatus;
use DevactionLabs\Zenith\Queues\QueueSummary;
use DevactionLabs\Zenith\Queues\QueueWaitThresholdStatus;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\Connections\Connection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\TagRepository;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturns;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\Zenith\Tests\Support\dashboardThrows;
use function DevactionLabs\Zenith\Tests\Support\horizonBatch;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

function queueSummaryCoordinator(
    JobRepository $jobRepository,
    BatchRepository $batchRepository,
    MetricsRepository $metrics,
    ?SnapshotJobsPerMinute $snapshotJobsPerMinute = null,
): QueueSummary {
    $jobs = new JobsData($jobRepository);
    $batches = new BatchesData(
        $batchRepository,
        new BatchJobsData($jobRepository, $jobs),
    );

    return new QueueSummary(
        new QueueJobsData(
            $jobRepository,
            $jobs,
            new FailedJobsData(
                $jobRepository,
                mockDashboardContract(TagRepository::class),
                $jobs,
                new FailedJobRetryEligibility,
            ),
            app(CacheFactory::class),
        ),
        new QueueBatchesData($batchRepository, $batches, app(CacheFactory::class)),
        $metrics,
        $snapshotJobsPerMinute ?? queueSummarySnapshotJobsPerMinute(),
    );
}

function queueSummarySnapshotJobsPerMinute(
    mixed $lastSnapshotAt = 'not-used',
    bool $failRedis = false,
    bool $optional = false,
): SnapshotJobsPerMinute {
    $connection = mockDashboardContract(Connection::class);
    $redis = mockDashboardContract(RedisFactory::class);

    if ($failRedis) {
        if ($optional) {
            dashboardExpects(
                $redis,
                'connection',
                times: 'zeroOrMoreTimes',
                exception: new RuntimeException('redis secret'),
            );
        } else {
            dashboardThrows($redis, 'connection', new RuntimeException('redis secret'));
        }

        return new SnapshotJobsPerMinute($redis);
    }

    if ($optional) {
        dashboardExpects(
            $connection,
            'get',
            ['last_snapshot_at'],
            times: 'zeroOrMoreTimes',
            value: $lastSnapshotAt,
        );
        dashboardExpects(
            $redis,
            'connection',
            times: 'zeroOrMoreTimes',
            value: $connection,
        );

        return new SnapshotJobsPerMinute($redis);
    }

    dashboardReturnsFor($connection, 'get', ['last_snapshot_at'], $lastSnapshotAt);
    dashboardReturns($redis, 'connection', $connection);

    return new SnapshotJobsPerMinute($redis);
}

function queueSummaryRow(): QueueRowData
{
    $pauseStatus = new QueuePauseStatus(
        app(QueueManager::class),
        new QueuePauseMetadata(app(CacheFactory::class)),
    );
    $redis = $pauseStatus->for('redis', 'reports');
    $sqs = $pauseStatus->for('sqs', 'reports');

    return new QueueRowData(
        name: 'reports',
        connections: ['redis', 'sqs'],
        pauseTargets: [
            new QueuePauseTargetData(
                connection: 'redis',
                paused: $redis->paused,
                pausedUntil: $redis->pausedUntil,
                ready: 3,
                reserved: 1,
                delayed: 0,
                total: 4,
            ),
            new QueuePauseTargetData(
                connection: 'sqs',
                paused: $sqs->paused,
                pausedUntil: $sqs->pausedUntil,
                ready: 4,
                reserved: 1,
                delayed: 3,
                total: 8,
            ),
        ],
        ready: 7,
        reserved: 2,
        delayed: 3,
        processes: 5,
        wait: 12,
        waitThreshold: new QueueWaitThresholdData(
            status: QueueWaitThresholdStatus::Exceeded,
            decisiveConnection: 'redis',
            waitSeconds: 8,
            thresholdSeconds: 5,
            oldestReadyAgeSeconds: 300,
            oldestReadyConnection: 'redis',
            targets: [
                new QueueWaitThresholdTargetData(
                    connection: 'redis',
                    status: QueueWaitThresholdStatus::Exceeded,
                    monitored: true,
                    waitSeconds: 8,
                    thresholdSeconds: 5,
                    oldestReadyAgeSeconds: 300,
                ),
                new QueueWaitThresholdTargetData(
                    connection: 'sqs',
                    status: QueueWaitThresholdStatus::Exceeded,
                    monitored: true,
                    waitSeconds: 12,
                    thresholdSeconds: 10,
                    oldestReadyAgeSeconds: 120,
                ),
            ],
        ),
    );
}

beforeEach(function (): void {
    app(CacheFactory::class)->store()->clear();
    config()->set('zenith.poll_interval', 0);
    CarbonImmutable::setTestNow('2026-07-18 12:00:00 UTC');

    if (queuePausingIsSupported()) {
        app(QueueManager::class)->resume('redis', 'reports');
        app(QueueManager::class)->resume('sqs', 'reports');
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('combines live queue retained history batches and snapshot metrics', function (): void {
    requireTimedQueuePausing();

    $deadline = CarbonImmutable::now()->addHour();
    $metadata = new QueuePauseMetadata(app(CacheFactory::class));
    $metadata->storeUntil('sqs', 'reports', $deadline);
    app(QueueManager::class)->pauseFor('sqs', 'reports', $deadline);

    $jobs = mockDashboardContract(JobRepository::class);
    $pending = horizonJob(0, 'pending-1');
    $pending->queue = 'reports';
    dashboardReturnsFor($jobs, 'countPending', [], 1);
    dashboardReturnsFor($jobs, 'getPending', ['-1'], collect([$pending]));
    $completed = horizonJob(0, 'completed-1');
    $completed->queue = 'reports';
    dashboardReturnsFor($jobs, 'countCompleted', [], 1);
    dashboardReturnsFor($jobs, 'getCompleted', ['-1'], collect([$completed]));
    $failed = horizonJob(0, 'failed-1');
    $failed->queue = 'reports';
    dashboardReturnsFor($jobs, 'countFailed', [], 1);
    dashboardReturnsFor($jobs, 'getFailed', ['-1'], collect([$failed]));
    $silenced = horizonJob(0, 'silenced-1');
    $silenced->queue = 'reports';
    dashboardReturnsFor($jobs, 'countSilenced', [], 1);
    dashboardReturnsFor($jobs, 'getSilenced', ['-1'], collect([$silenced]));

    $batch = horizonBatch('batch-1', pendingJobs: 4);
    $batch->options['queue'] = 'reports';
    $batchRepository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batchRepository, 'get', [50, null], [$batch]);
    dashboardReturnsFor($batchRepository, 'get', [50, 'batch-1'], []);

    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturnsFor($metrics, 'throughputForQueue', ['reports'], 4);
    dashboardReturnsFor($metrics, 'runtimeForQueue', ['reports'], 2500.0);

    $summary = queueSummaryCoordinator(
        $jobs,
        $batchRepository,
        $metrics,
        queueSummarySnapshotJobsPerMinute(
            (string) CarbonImmutable::now()->subSeconds(30)->getTimestamp(),
        ),
    )->forQueue(queueSummaryRow());

    expect($summary->toArray())->toMatchArray([
        'available' => true,
        'name' => 'reports',
        'connections' => ['redis', 'sqs'],
        'pauseTargets' => [
            [
                'connection' => 'redis',
                'paused' => false,
                'pausedUntil' => null,
                'ready' => 3,
                'reserved' => 1,
                'delayed' => 0,
                'total' => 4,
            ],
            [
                'connection' => 'sqs',
                'paused' => true,
                'pausedUntil' => $deadline->timestamp,
                'ready' => 4,
                'reserved' => 1,
                'delayed' => 3,
                'total' => 8,
            ],
        ],
        'pendingJobs' => 1,
        'pendingComplete' => true,
        'pendingReserved' => 2,
        'pendingReadyNow' => 7,
        'pendingDelayed' => 3,
        'failedJobs' => 1,
        'failedComplete' => true,
        'failedJobsPerMinuteComplete' => true,
        'failedJobsPastHourComplete' => true,
        'failedJobsPastDayComplete' => true,
        'failedRetentionMinutes' => 10080,
        'completedJobs' => 1,
        'completedAvailable' => true,
        'completedComplete' => true,
        'completedJobsPerMinuteComplete' => true,
        'completedJobsPastHourComplete' => true,
        'completedJobsPastDayComplete' => true,
        'completedRetentionMinutes' => 60,
        'silencedJobs' => 1,
        'silencedComplete' => true,
        'batches' => 1,
        'activeBatches' => 1,
        'batchesComplete' => true,
        'processes' => 5,
        'waitThreshold' => [
            'status' => 'exceeded',
            'decisiveConnection' => 'redis',
            'waitSeconds' => 8,
            'thresholdSeconds' => 5,
            'oldestReadyAgeSeconds' => 300,
            'oldestReadyConnection' => 'redis',
            'targets' => [
                [
                    'connection' => 'redis',
                    'status' => 'exceeded',
                    'monitored' => true,
                    'waitSeconds' => 8,
                    'thresholdSeconds' => 5,
                    'oldestReadyAgeSeconds' => 300,
                ],
                [
                    'connection' => 'sqs',
                    'status' => 'exceeded',
                    'monitored' => true,
                    'waitSeconds' => 12,
                    'thresholdSeconds' => 10,
                    'oldestReadyAgeSeconds' => 120,
                ],
            ],
        ],
        'jobsPerMinute' => 8.0,
        'throughput' => 4,
        'averageRuntime' => 2.5,
        'message' => null,
        'routing' => [
            'available' => app()->bound('queue.routes'),
            'classRoutes' => [],
            'forwardedQueue' => null,
            'forwardedConnection' => null,
        ],
    ]);
});

it('projects queue jobs per minute from throughputForQueue and the shared snapshot calculator', function (): void {
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($jobs, 'countPending', [], 0);
    dashboardReturnsFor($jobs, 'countCompleted', [], 0);
    dashboardReturnsFor($jobs, 'countFailed', [], 0);
    dashboardReturnsFor($jobs, 'countSilenced', [], 0);
    $batchRepository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batchRepository, 'get', [50, null], []);
    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturnsFor($metrics, 'throughputForQueue', ['reports'], 100);
    dashboardReturnsFor($metrics, 'runtimeForQueue', ['reports'], 0.0);

    $summary = queueSummaryCoordinator(
        $jobs,
        $batchRepository,
        $metrics,
        queueSummarySnapshotJobsPerMinute(
            (string) CarbonImmutable::now()->subSeconds(30)->getTimestamp(),
        ),
    )->forQueue(queueSummaryRow());

    expect($summary->jobsPerMinute)->toBe(200.0)
        ->and($summary->throughput)->toBe(100);
});

it('returns zero jobs per minute when the snapshot timestamp is unusable while keeping throughput', function (): void {
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($jobs, 'countPending', [], 0);
    dashboardReturnsFor($jobs, 'countCompleted', [], 0);
    dashboardReturnsFor($jobs, 'countFailed', [], 0);
    dashboardReturnsFor($jobs, 'countSilenced', [], 0);
    $batchRepository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batchRepository, 'get', [50, null], []);
    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturnsFor($metrics, 'throughputForQueue', ['reports'], 40);
    dashboardReturnsFor($metrics, 'runtimeForQueue', ['reports'], 0.0);

    $summary = queueSummaryCoordinator(
        $jobs,
        $batchRepository,
        $metrics,
        queueSummarySnapshotJobsPerMinute(null),
    )->forQueue(queueSummaryRow());

    expect($summary->jobsPerMinute)->toBe(0)
        ->and($summary->throughput)->toBe(40)
        ->and($summary->averageRuntime)->toBe(0.0);
});

it('keeps queue data available when snapshot metrics fail', function (): void {
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($jobs, 'countPending', [], 0);
    dashboardReturnsFor($jobs, 'countCompleted', [], 0);
    dashboardReturnsFor($jobs, 'countFailed', [], 0);
    dashboardReturnsFor($jobs, 'countSilenced', [], 0);
    $batchRepository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batchRepository, 'get', [50, null], []);
    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardThrows($metrics, 'throughputForQueue', new RuntimeException('metrics secret'));

    $summary = queueSummaryCoordinator(
        $jobs,
        $batchRepository,
        $metrics,
        queueSummarySnapshotJobsPerMinute(optional: true),
    )->forQueue(queueSummaryRow());

    expect($summary->available)->toBeTrue()
        ->and($summary->processes)->toBe(5)
        ->and($summary->jobsPerMinute)->toBeNull()
        ->and($summary->throughput)->toBeNull()
        ->and($summary->averageRuntime)->toBeNull()
        ->and($summary->message)->toBeNull();
});

it('returns zero jobs per minute when redis snapshot lookup fails without nulling throughput', function (): void {
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($jobs, 'countPending', [], 0);
    dashboardReturnsFor($jobs, 'countCompleted', [], 0);
    dashboardReturnsFor($jobs, 'countFailed', [], 0);
    dashboardReturnsFor($jobs, 'countSilenced', [], 0);
    $batchRepository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batchRepository, 'get', [50, null], []);
    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturnsFor($metrics, 'throughputForQueue', ['reports'], 12);
    dashboardReturnsFor($metrics, 'runtimeForQueue', ['reports'], 500.0);

    $summary = queueSummaryCoordinator(
        $jobs,
        $batchRepository,
        $metrics,
        queueSummarySnapshotJobsPerMinute(failRedis: true),
    )->forQueue(queueSummaryRow());

    expect($summary->available)->toBeTrue()
        ->and($summary->jobsPerMinute)->toBe(0)
        ->and($summary->throughput)->toBe(12)
        ->and($summary->averageRuntime)->toBe(0.5);
});

it('preserves partial retained-data warnings in the composed queue summary', function (): void {
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($jobs, 'countPending', [], 1);
    dashboardThrows($jobs, 'getPending', new RuntimeException('pending secret'));
    dashboardReturnsFor($jobs, 'countCompleted', [], 0);
    dashboardReturnsFor($jobs, 'countFailed', [], 0);
    dashboardReturnsFor($jobs, 'countSilenced', [], 0);

    $batchRepository = mockDashboardContract(BatchRepository::class);
    dashboardThrows($batchRepository, 'get', new RuntimeException('batch secret'));

    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturnsFor($metrics, 'throughputForQueue', ['reports'], 0);

    $summary = queueSummaryCoordinator($jobs, $batchRepository, $metrics)
        ->forQueue(queueSummaryRow());

    expect($summary->available)->toBeTrue()
        ->and($summary->pendingComplete)->toBeFalse()
        ->and($summary->batchesComplete)->toBeFalse()
        ->and($summary->message)->toBe(
            'Some retained job data is currently unavailable. Retained batches are currently unavailable.',
        );
});

it('keeps a completed-only calculation failure quiet without inventing a zero', function (): void {
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardReturnsFor($jobs, 'countPending', [], 0);
    dashboardReturnsFor($jobs, 'countCompleted', [], 1);
    dashboardThrows($jobs, 'getCompleted', new RuntimeException('completed secret'));
    dashboardReturnsFor($jobs, 'countFailed', [], 0);
    dashboardReturnsFor($jobs, 'countSilenced', [], 0);

    $batchRepository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batchRepository, 'get', [50, null], []);

    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturnsFor($metrics, 'throughputForQueue', ['reports'], 0);

    $summary = queueSummaryCoordinator($jobs, $batchRepository, $metrics)
        ->forQueue(queueSummaryRow());

    expect($summary->available)->toBeTrue()
        ->and($summary->completedJobs)->toBeNull()
        ->and($summary->completedAvailable)->toBeFalse()
        ->and($summary->completedComplete)->toBeFalse()
        ->and($summary->message)->toBeNull();
});

it('maps a warming retained summary to unknown counts without making the queue unavailable', function (): void {
    config()->set('zenith.poll_interval', 5_000);

    $prefix = config('horizon.prefix', 'horizon:');
    $prefix = is_string($prefix) ? $prefix : 'horizon:';
    $cacheKey = 'zenith:queue-jobs:'.hash(
        'sha256',
        $prefix."\0reports",
    );
    app(CacheFactory::class)->store()->forever(
        $cacheKey,
        QueueRetainedJobsData::warming()->toArray(),
    );

    $jobs = mockDashboardContract(JobRepository::class);
    $batchRepository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsFor($batchRepository, 'get', [50, null], []);
    $metrics = mockDashboardContract(MetricsRepository::class);
    dashboardReturnsFor($metrics, 'throughputForQueue', ['reports'], 0);

    $summary = queueSummaryCoordinator($jobs, $batchRepository, $metrics)
        ->forQueue(queueSummaryRow());

    expect($summary->available)->toBeTrue()
        ->and($summary->retainedJobsWarming)->toBeTrue()
        ->and($summary->pendingJobs)->toBeNull()
        ->and($summary->pendingComplete)->toBeFalse()
        ->and($summary->failedJobs)->toBeNull()
        ->and($summary->failedJobsPerMinute)->toBeNull()
        ->and($summary->failedJobsPastHour)->toBeNull()
        ->and($summary->failedJobsPastDay)->toBeNull()
        ->and($summary->completedJobs)->toBeNull()
        ->and($summary->completedAvailable)->toBeFalse()
        ->and($summary->silencedJobs)->toBeNull()
        ->and($summary->message)->toBeNull()
        ->and($summary->processes)->toBe(5);
});

it('distinguishes neutral retained-index warming from true retained-data failure', function (): void {
    $warming = QueueRetainedJobsData::warming();
    $unavailable = QueueRetainedJobsData::unavailable();

    expect($warming->warming)->toBeTrue()
        ->and($warming->message)->toBeNull()
        ->and($unavailable->warming)->toBeFalse()
        ->and($unavailable->message)->toBe('Some retained job data is currently unavailable.');
});

it('creates an explicit unavailable summary without inventing zero values', function (): void {
    $summary = QueueSummary::unavailable('reports', 'Horizon queues are currently unavailable.');

    expect($summary->available)->toBeFalse()
        ->and($summary->retainedJobsWarming)->toBeFalse()
        ->and($summary->name)->toBe('reports')
        ->and($summary->pendingJobs)->toBeNull()
        ->and($summary->silencedJobs)->toBeNull()
        ->and($summary->processes)->toBeNull()
        ->and($summary->waitThreshold)->toBeNull()
        ->and($summary->jobsPerMinute)->toBeNull()
        ->and($summary->throughput)->toBeNull()
        ->and($summary->averageRuntime)->toBeNull()
        ->and($summary->message)->toBe('Horizon queues are currently unavailable.');
});
