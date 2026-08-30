<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DevactionLabs\HorizonNewDawn\Batches\BatchJobsData;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchMetadata;
use DevactionLabs\HorizonNewDawn\Jobs\JobsData;
use DevactionLabs\HorizonNewDawn\Jobs\PendingJobEntryScanner;
use DevactionLabs\HorizonNewDawn\Tests\Support\HorizonJob;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturnsFor;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardThrows;
use function DevactionLabs\HorizonNewDawn\Tests\Support\horizonBatch;
use function DevactionLabs\HorizonNewDawn\Tests\Support\horizonJob;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

describe('BatchJobsData', function (): void {
    it('returns authoritative retained pending completed and failed batch jobs', function (): void {
        $batch = horizonBatch(
            'batch-42',
            totalJobs: 5,
            pendingJobs: 2,
            failedJobs: 1,
            failedJobIds: ['failed-1'],
        );
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($repository, 'getPending', [null], collect([
            retainedBatchJob(0, 'pending-1', 'batch-42', 'pending'),
            retainedBatchJob(1, 'other-pending', 'other-batch', 'pending'),
        ]));
        dashboardReturnsFor($repository, 'getCompleted', [null], collect([
            retainedBatchJob(0, 'completed-1', 'batch-42', 'completed'),
            retainedBatchJob(1, 'completed-2', 'batch-42', 'completed'),
            retainedBatchJob(2, 'completed-3', 'batch-42', 'completed'),
        ]));
        dashboardReturnsFor($repository, 'getJobs', [['failed-1']], collect([
            retainedBatchJob(0, 'failed-1', 'batch-42', 'failed'),
        ]));

        $lists = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch);

        expect($lists->pending->total)->toBe(1)
            ->and($lists->pending->rows)->toHaveCount(1)
            ->and($lists->pending->rows[0]->id)->toBe('pending-1')
            ->and($lists->completed->total)->toBe(3)
            ->and($lists->completed->rows)->toHaveCount(3)
            ->and($lists->failed->total)->toBe(1)
            ->and($lists->failed->rows)->toHaveCount(1)
            ->and($lists->pending->complete)->toBeTrue()
            ->and($lists->completed->complete)->toBeTrue()
            ->and($lists->failed->complete)->toBeTrue();
    });

    it('does not count failed batch jobs as pending jobs', function (): void {
        $batch = horizonBatch(
            'batch-42',
            totalJobs: 4,
            pendingJobs: 1,
            failedJobs: 1,
            failedJobIds: ['failed-1'],
        );
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($repository, 'getCompleted', [null], collect([
            retainedBatchJob(0, 'completed-1', 'batch-42', 'completed'),
            retainedBatchJob(1, 'completed-2', 'batch-42', 'completed'),
            retainedBatchJob(2, 'completed-3', 'batch-42', 'completed'),
        ]));
        dashboardReturnsFor($repository, 'getJobs', [['failed-1']], collect([
            retainedBatchJob(3, 'failed-1', 'batch-42', 'failed'),
        ]));

        $lists = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch);

        expect($lists->pending->total)->toBe(0)
            ->and($lists->pending->rows)->toBe([])
            ->and($lists->completed->total)->toBe(3)
            ->and($lists->completed->rows)->toHaveCount(3)
            ->and($lists->failed->total)->toBe(1);
    });

    it('does not query Horizon for zero-count job lists', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('empty', totalJobs: 0, pendingJobs: 0);

        $lists = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch);

        expect($lists->pending->rows)->toBe([])
            ->and($lists->completed->rows)->toBe([])
            ->and($lists->failed->rows)->toBe([])
            ->and($lists->pending->complete)->toBeTrue()
            ->and($lists->completed->complete)->toBeTrue()
            ->and($lists->failed->complete)->toBeTrue();
    });

    it('marks a short retained page with missing matches as incomplete', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 2, pendingJobs: 2);
        dashboardReturnsFor($repository, 'getPending', [null], collect([
            retainedBatchJob(0, 'pending-1', 'batch-42', 'pending'),
            retainedBatchJob(1, 'other-pending', 'other-batch', 'pending'),
        ]));

        $pending = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch)->pending;

        expect($pending->available)->toBeTrue()
            ->and($pending->complete)->toBeFalse()
            ->and($pending->rows)->toHaveCount(1)
            ->and($pending->message)->toBe('Some pending jobs are no longer retained by Horizon.');
    });

    it('advances a full retained page using its final Horizon index', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 1, pendingJobs: 1);
        dashboardReturnsFor($repository, 'getPending', [null], collect(array_map(
            static fn (int $index): HorizonJob => retainedBatchJob($index, "other-{$index}", 'other-batch', 'pending'),
            range(0, 49),
        )));
        dashboardReturnsFor($repository, 'getPending', ['49'], collect([
            retainedBatchJob(50, 'pending-1', 'batch-42', 'pending'),
        ]));

        $pending = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch)->pending;

        expect($pending->complete)->toBeTrue()
            ->and($pending->rows)->toHaveCount(1)
            ->and($pending->rows[0]->id)->toBe('pending-1');
    });

    it('continues scanning retained jobs past the former 250 ceiling until the page ends', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 1, pendingJobs: 1);

        foreach ([null, '49', '99', '149', '199'] as $page => $cursor) {
            $start = $page * 50;
            dashboardReturnsFor($repository, 'getPending', [$cursor], collect(array_map(
                static fn (int $index): HorizonJob => retainedBatchJob($index, "other-{$index}", 'other-batch', 'pending'),
                range($start, $start + 49),
            )));
        }
        dashboardReturnsFor($repository, 'getPending', ['249'], collect([
            retainedBatchJob(250, 'pending-1', 'batch-42', 'pending'),
        ]));

        $pending = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch)->pending;

        expect($pending->available)->toBeTrue()
            ->and($pending->complete)->toBeTrue()
            ->and($pending->rows)->toHaveCount(1)
            ->and($pending->rows[0]->id)->toBe('pending-1');
    });

    it('lists live queue-backed pending jobs without scanning retained pending history', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 3, pendingJobs: 2);
        dashboardNeverReceives($repository, 'getPending');
        dashboardReturnsFor($repository, 'getCompleted', [null], collect([
            retainedBatchJob(0, 'completed-1', 'batch-42', 'completed'),
        ]));
        dashboardReturnsUsing($repository, 'getJobs', static fn (array $ids): Collection => collect());

        $pendingStates = fakePendingJobEntryScanner([
            [
                'id' => 'queue-pending-1',
                'state' => 'ready',
                'connection' => 'redis',
                'queue' => 'imports',
                'score' => null,
                'payload' => queueBatchPayload('queue-pending-1', 'batch-42', 'App\\Jobs\\ImportFeed'),
            ],
            [
                'id' => 'queue-pending-2',
                'state' => 'ready',
                'connection' => 'redis',
                'queue' => 'imports',
                'score' => null,
                'payload' => queueBatchPayload('queue-pending-2', 'batch-42', 'App\\Jobs\\ExportFeed'),
            ],
        ]);

        $pending = (new BatchJobsData(
            $repository,
            new JobsData($repository),
            $pendingStates,
        ))->forBatch($batch)->pending;

        expect($pending->available)->toBeTrue()
            ->and($pending->complete)->toBeTrue()
            ->and($pending->total)->toBe(2)
            ->and($pending->rows)->toHaveCount(2)
            ->and($pending->rows[0]->id)->toBe('queue-pending-1')
            ->and($pending->rows[0]->status)->toBe('pending')
            ->and($pending->rows[0]->inspectable)->toBeFalse()
            ->and($pending->rows[0]->name)->toBe('App\\Jobs\\ImportFeed')
            ->and($pending->rows[0]->queue)->toBe('imports')
            ->and($pending->rows[1]->id)->toBe('queue-pending-2')
            ->and($pending->message)->toBeNull()
            ->and(json_encode($pending->rows))->not->toContain('serialized-secret-command');
    });

    it('hydrates still-retained pending IDs via getJobs without calling getPending', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 4, pendingJobs: 3);
        dashboardNeverReceives($repository, 'getPending');
        dashboardReturnsFor($repository, 'getCompleted', [null], collect([
            retainedBatchJob(0, 'completed-1', 'batch-42', 'completed'),
        ]));
        dashboardReturnsUsing($repository, 'getJobs', static function (array $ids): Collection {
            expect($ids)->toBe(['retained-pending', 'queue-reserved', 'queue-delayed']);

            return collect([
                retainedBatchJob(0, 'retained-pending', 'batch-42', 'pending'),
            ]);
        });

        $pendingStates = fakePendingJobEntryScanner([
            [
                'id' => 'retained-pending',
                'state' => 'ready',
                'connection' => 'redis',
                'queue' => 'imports',
                'score' => null,
                'payload' => queueBatchPayload('retained-pending', 'batch-42'),
            ],
            [
                'id' => 'queue-reserved',
                'state' => 'reserved',
                'connection' => 'redis',
                'queue' => 'imports',
                'score' => 1_784_281_100.0,
                'payload' => queueBatchPayload('queue-reserved', 'batch-42', 'App\\Jobs\\ReservedFeed'),
            ],
            [
                'id' => 'queue-delayed',
                'state' => 'delayed',
                'connection' => 'redis',
                'queue' => 'imports',
                'score' => 1_784_281_900.0,
                'payload' => queueBatchPayload(
                    'queue-delayed',
                    'batch-42',
                    'App\\Jobs\\DelayedFeed',
                    delay: 600,
                    createdAt: 1_784_281_000.0,
                ),
            ],
        ]);

        $pending = (new BatchJobsData(
            $repository,
            new JobsData($repository),
            $pendingStates,
        ))->forBatch($batch)->pending;

        expect($pending->complete)->toBeTrue()
            ->and($pending->rows)->toHaveCount(3)
            ->and(array_map(static fn ($row): string => $row->id, $pending->rows))->toBe([
                'retained-pending',
                'queue-reserved',
                'queue-delayed',
            ])
            ->and($pending->rows[0]->status)->toBe('pending')
            ->and($pending->rows[0]->inspectable)->toBeTrue()
            ->and($pending->rows[1]->status)->toBe('reserved')
            ->and($pending->rows[1]->reservedAt)->toBeNull()
            ->and($pending->rows[1]->runtime)->toBeNull()
            ->and($pending->rows[1]->inspectable)->toBeFalse()
            ->and($pending->rows[2]->status)->toBe('pending')
            ->and($pending->rows[2]->scheduledAt)->toBe(1_784_281_900.0)
            ->and($pending->message)->toBeNull();
    });

    it('keeps the queue-snapshot row when a hydrated hash is no longer pending or reserved', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 1, pendingJobs: 1);
        dashboardNeverReceives($repository, 'getPending');
        dashboardReturnsUsing($repository, 'getJobs', static fn (array $ids): Collection => collect([
            retainedBatchJob(0, 'race-completed', 'batch-42', 'completed'),
        ]));

        $pending = (new BatchJobsData(
            $repository,
            new JobsData($repository),
            fakePendingJobEntryScanner([[
                'id' => 'race-completed',
                'state' => 'ready',
                'connection' => 'redis',
                'queue' => 'imports',
                'score' => null,
                'payload' => queueBatchPayload('race-completed', 'batch-42'),
            ]]),
        ))->forBatch($batch)->pending;

        expect($pending->rows)->toHaveCount(1)
            ->and($pending->rows[0]->id)->toBe('race-completed')
            ->and($pending->rows[0]->status)->toBe('pending')
            ->and($pending->rows[0]->inspectable)->toBeFalse()
            ->and($pending->rows[0]->completedAt)->toBeNull();
    });

    it('uses stored sidecar attribution for the live pending queue target', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        // Options omit destination so only the sidecar can resolve the historical queue.
        $batch = new Batch(
            queue: mockDashboardContract(QueueFactory::class),
            repository: mockDashboardContract(BatchRepository::class),
            id: 'batch-42',
            name: 'Import customer records',
            totalJobs: 1,
            pendingJobs: 1,
            failedJobs: 0,
            failedJobIds: [],
            options: [],
            createdAt: CarbonImmutable::createFromTimestampUTC(1_784_281_000),
        );
        dashboardNeverReceives($repository, 'getPending');
        dashboardReturnsUsing($repository, 'getJobs', static fn (array $ids): Collection => collect());
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
        ]);

        $pendingStates = new class implements PendingJobEntryScanner
        {
            /** @var array{connection: string, queue: string}|null */
            public ?array $target = null;

            public function pendingQueueEntries(array $target): array
            {
                $this->target = $target;

                return [[
                    'id' => 'queue-only',
                    'state' => 'ready',
                    'connection' => $target['connection'],
                    'queue' => $target['queue'],
                    'score' => null,
                    'payload' => queueBatchPayload('queue-only', 'batch-42'),
                ]];
            }
        };

        $attribution = new DatabaseBatchMetadata(
            batchId: 'batch-42',
            queue: 'historical-imports',
            connection: 'redis',
            queueIsExplicit: false,
            connectionIsExplicit: false,
        );

        $pending = (new BatchJobsData(
            $repository,
            new JobsData($repository),
            $pendingStates,
        ))->forBatch($batch, $attribution)->pending;

        expect($pending->complete)->toBeTrue()
            ->and($pending->rows)->toHaveCount(1)
            ->and($pending->rows[0]->queue)->toBe('historical-imports')
            ->and($pendingStates->target)->toBe([
                'connection' => 'redis',
                'queue' => 'historical-imports',
            ]);
    });

    it('prefers the delayed snapshot score as scheduledAt for released queue-only rows', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 1, pendingJobs: 1);
        dashboardNeverReceives($repository, 'getPending');
        dashboardReturnsUsing($repository, 'getJobs', static fn (array $ids): Collection => collect());

        $pending = (new BatchJobsData(
            $repository,
            new JobsData($repository),
            fakePendingJobEntryScanner([[
                'id' => 'queue-released',
                'state' => 'released',
                'connection' => 'redis',
                'queue' => 'imports',
                'score' => 1_784_281_050.0,
                'payload' => queueBatchPayload(
                    'queue-released',
                    'batch-42',
                    delay: 50,
                    createdAt: 1_784_281_000.0,
                ),
            ]]),
        ))->forBatch($batch)->pending;

        expect($pending->rows)->toHaveCount(1)
            ->and($pending->rows[0]->status)->toBe('pending')
            ->and($pending->rows[0]->scheduledAt)->toBe(1_784_281_050.0)
            ->and($pending->rows[0]->reservedAt)->toBeNull()
            ->and($pending->rows[0]->runtime)->toBeNull()
            ->and($pending->rows[0]->inspectable)->toBeFalse();
    });

    it('falls back to the retained pending scan when the live queue snapshot fails', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 1, pendingJobs: 1);
        dashboardReturnsFor($repository, 'getPending', [null], collect([
            retainedBatchJob(0, 'retained-after-queue-failure', 'batch-42', 'pending'),
        ]));

        $pendingStates = new class implements PendingJobEntryScanner
        {
            public function pendingQueueEntries(array $target): array
            {
                throw new RuntimeException('Pending state is unavailable for [sync].');
            }
        };

        $pending = (new BatchJobsData(
            $repository,
            new JobsData($repository),
            $pendingStates,
        ))->forBatch($batch)->pending;

        expect($pending->available)->toBeTrue()
            ->and($pending->complete)->toBeTrue()
            ->and($pending->rows)->toHaveCount(1)
            ->and($pending->rows[0]->id)->toBe('retained-after-queue-failure')
            ->and($pending->message)->toBeNull();
    });

    it('reports unavailable pending jobs when both the live queue and retained scans fail', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 1, pendingJobs: 1);
        dashboardThrows($repository, 'getPending', new RuntimeException('redis password leaked'));

        $pendingStates = new class implements PendingJobEntryScanner
        {
            public function pendingQueueEntries(array $target): array
            {
                throw new RuntimeException('Pending state is unavailable for [sync].');
            }
        };

        $pending = (new BatchJobsData(
            $repository,
            new JobsData($repository),
            $pendingStates,
        ))->forBatch($batch)->pending;

        expect($pending->available)->toBeFalse()
            ->and($pending->complete)->toBeFalse()
            ->and($pending->rows)->toBe([])
            ->and($pending->message)->toBe('Pending jobs for this batch are currently unavailable.')
            ->and($pending->message)->not->toContain('sync')
            ->and($pending->message)->not->toContain('password');
    });

    it('stops safely when Horizon returns a non-advancing cursor', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 1, pendingJobs: 1);
        $page = collect(array_map(
            static fn (int $index): HorizonJob => retainedBatchJob($index, "other-{$index}", 'other-batch', 'pending'),
            range(0, 49),
        ));
        dashboardReturnsFor($repository, 'getPending', [null], $page);
        dashboardReturnsFor($repository, 'getPending', ['49'], $page);

        $pending = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch)->pending;

        expect($pending->available)->toBeTrue()
            ->and($pending->complete)->toBeFalse()
            ->and($pending->rows)->toBe([]);
    });

    it('isolates a completed repository failure from the other job lists', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch('batch-42', totalJobs: 2, pendingJobs: 0);
        dashboardThrows($repository, 'getCompleted', new RuntimeException('redis password leaked'));

        $lists = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch);

        expect($lists->pending->available)->toBeTrue()
            ->and($lists->failed->available)->toBeTrue()
            ->and($lists->completed->available)->toBeFalse()
            ->and($lists->completed->complete)->toBeFalse()
            ->and($lists->completed->message)->toBe('Completed jobs for this batch are currently unavailable.')
            ->and($lists->completed->message)->not->toContain('password');
    });

    it('marks missing failed hashes as incomplete instead of unavailable', function (): void {
        $repository = mockDashboardContract(JobRepository::class);
        $batch = horizonBatch(
            'batch-42',
            totalJobs: 1,
            pendingJobs: 1,
            failedJobs: 1,
            failedJobIds: ['failed-1'],
        );
        dashboardReturnsFor($repository, 'getJobs', [['failed-1']], new Collection);

        $failed = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch)->failed;

        expect($failed->available)->toBeTrue()
            ->and($failed->complete)->toBeFalse()
            ->and($failed->rows)->toBe([])
            ->and($failed->message)->toBe('Some failed jobs are no longer retained by Horizon.');
    });

    it('collapses retained retry chains into logical failed jobs and totals their attempts', function (): void {
        $batch = horizonBatch(
            'batch-42',
            totalJobs: 4,
            pendingJobs: 2,
            failedJobs: 4,
            failedJobIds: ['original-1', 'retry-1', 'retry-2', 'trimmed-lineage'],
        );
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($repository, 'getJobs', [$batch->failedJobIds], collect([
            retainedBatchRetry(0, 'original-1', null, 2, 'retry-1'),
            retainedBatchRetry(1, 'retry-1', 'original-1', 1, 'retry-2'),
            retainedBatchRetry(2, 'retry-2', 'retry-1', 3),
        ]));

        $failed = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch)->failed;

        expect($failed->total)->toBe(2)
            ->and($failed->rows)->toHaveCount(1)
            ->and($failed->rows[0]->id)->toBe('retry-2')
            ->and($failed->rows[0]->attempts)->toBe(6)
            ->and($failed->rows[0]->attemptsComplete)->toBeTrue()
            ->and($failed->complete)->toBeFalse()
            ->and($failed->message)->toBe('Some failed jobs are no longer retained by Horizon.');
    });

    it('reports a lower-bound attempt total when the start of a retry chain was trimmed', function (): void {
        $batch = horizonBatch(
            'batch-42',
            totalJobs: 1,
            pendingJobs: 1,
            failedJobs: 2,
            failedJobIds: ['trimmed-parent', 'retry-1'],
        );
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($repository, 'getJobs', [$batch->failedJobIds], collect([
            retainedBatchRetry(1, 'retry-1', 'trimmed-parent', 2),
        ]));

        $failed = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch)->failed;

        expect($failed->total)->toBe(1)
            ->and($failed->rows)->toHaveCount(1)
            ->and($failed->rows[0]->attempts)->toBe(3)
            ->and($failed->rows[0]->attemptsComplete)->toBeFalse()
            ->and($failed->complete)->toBeFalse();
    });

    it('does not count a queued retry as an unknown execution attempt', function (): void {
        $batch = horizonBatch(
            'batch-42',
            totalJobs: 1,
            pendingJobs: 1,
            failedJobs: 1,
            failedJobIds: ['original-1'],
        );
        $original = retainedBatchRetry(0, 'original-1', null, 2);
        $original->retried_by = json_encode([
            ['id' => 'pending-retry', 'status' => 'pending'],
        ], JSON_THROW_ON_ERROR);
        $repository = mockDashboardContract(JobRepository::class);
        dashboardReturnsFor($repository, 'getJobs', [$batch->failedJobIds], collect([$original]));

        $failed = (new BatchJobsData($repository, new JobsData($repository)))->forBatch($batch)->failed;

        expect($failed->rows)->toHaveCount(1)
            ->and($failed->rows[0]->attempts)->toBe(2)
            ->and($failed->rows[0]->attemptsComplete)->toBeTrue()
            ->and($failed->complete)->toBeTrue();
    });
});

function retainedBatchJob(
    int $index,
    string $id,
    string $batchId,
    string $status,
): HorizonJob {
    $job = horizonJob($index, $id);
    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['data']['batchId'] = $batchId;
    $job->payload = json_encode($payload, JSON_THROW_ON_ERROR);
    $job->status = $status;

    if ($status === 'pending') {
        $job->completed_at = null;
    }

    if ($status === 'failed') {
        $job->completed_at = null;
        $job->failed_at = '1784281004.25';
    }

    return $job;
}

function retainedBatchRetry(
    int $index,
    string $id,
    ?string $retryOf,
    int $attempts,
    ?string $retriedBy = null,
): HorizonJob {
    $job = retainedBatchJob($index, $id, 'batch-42', 'failed');
    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['attempts'] = $attempts;

    if ($retryOf !== null) {
        $payload['retry_of'] = $retryOf;
    }

    $job->payload = json_encode($payload, JSON_THROW_ON_ERROR);
    $job->retried_by = $retriedBy === null
        ? null
        : json_encode([
            ['id' => $retriedBy, 'status' => 'failed'],
        ], JSON_THROW_ON_ERROR);

    return $job;
}

/**
 * @return array<string, mixed>
 */
function queueBatchPayload(
    string $id,
    string $batchId,
    string $name = 'App\\Jobs\\ImportFeed',
    ?int $delay = null,
    ?float $createdAt = null,
): array {
    return array_filter([
        'uuid' => $id,
        'id' => $id,
        'displayName' => $name,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'pushedAt' => 1_784_281_000.25,
        'createdAt' => $createdAt,
        'delay' => $delay,
        'attempts' => 0,
        'tags' => ['tenant:1'],
        'data' => [
            'commandName' => $name,
            'batchId' => $batchId,
            'command' => 'serialized-secret-command',
        ],
    ], static fn (mixed $value): bool => $value !== null);
}

/**
 * @param  list<array{
 *     id: string,
 *     state: 'ready'|'reserved'|'delayed'|'released',
 *     connection: string,
 *     queue: string,
 *     payload: array<string, mixed>,
 *     score: float|null
 * }>  $entries
 */
function fakePendingJobEntryScanner(array $entries): PendingJobEntryScanner
{
    return new class($entries) implements PendingJobEntryScanner
    {
        /**
         * @param  list<array{
         *     id: string,
         *     state: 'ready'|'reserved'|'delayed'|'released',
         *     connection: string,
         *     queue: string,
         *     payload: array<string, mixed>,
         *     score: float|null
         * }>  $entries
         */
        public function __construct(private array $entries) {}

        public function pendingQueueEntries(array $target): array
        {
            expect($target)->toBe(['connection' => 'redis', 'queue' => 'imports']);

            return $this->entries;
        }
    };
}
