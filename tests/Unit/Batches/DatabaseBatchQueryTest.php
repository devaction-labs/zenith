<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Batches\BatchCreatedRange;
use DevactionLabs\Zenith\Batches\BatchesData;
use DevactionLabs\Zenith\Batches\BatchJobsData;
use DevactionLabs\Zenith\Batches\BatchSort;
use DevactionLabs\Zenith\Batches\BatchSortDirection;
use DevactionLabs\Zenith\Batches\BatchStatus;
use DevactionLabs\Zenith\Batches\Data\BatchIndexFiltersData;
use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\Batches\DatabaseBatchMetadataSynchronizer;
use DevactionLabs\Zenith\Batches\DatabaseBatchQuery;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Queues\QueueBatchesData;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

beforeEach(function (): void {
    Date::setTestNow('2026-07-26 12:00:00');
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', 'default');
    config()->set('queue.connections.sqs.queue', 'inferred-sqs');

    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');

    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });

    $migration = require __DIR__.'/../../../database/migrations/2026_07_26_000000_create_zenith_batch_metadata_table.php';
    $migration->up();

    foreach (range(1, 101) as $index) {
        databaseBatchQueryInsertBatch(
            id: sprintf('batch-%03d', $index),
            name: $index === 26 ? 'Literal 100%_match' : sprintf('Batch %03d', $index),
            total: 100 + $index,
            pending: $index % 4 === 0 ? 0 : 30 + ($index % 7),
            failed: $index % 4 === 2 ? 3 : 0,
            options: match ($index) {
                26 => ['queue' => 'priority', 'connection' => 'redis'],
                27 => ['queue' => 'priority'],
                28 => ['connection' => 'sqs'],
                default => [],
            },
            createdAt: Date::now()->subHours(102 - $index)->getTimestamp(),
            cancelledAt: $index % 4 === 3 ? Date::now()->getTimestamp() : null,
            finishedAt: $index % 4 === 0 ? Date::now()->getTimestamp() : null,
        );
    }
});

afterEach(function (): void {
    Date::setTestNow();
    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');
});

it('filters all retained rows before pagination and includes first-observed inferred attribution', function (): void {
    $query = databaseBatchQuery();
    $filters = databaseBatchQueryFilters(
        query: '100%_match',
        queue: 'priority',
        connection: 'redis',
        created: BatchCreatedRange::Last7Days,
    );
    $page = $query->page($filters, null);

    expect(array_column($page->toArray()['batches'], 'id'))->toBe(['batch-026'])
        ->and($page->batches[0]->queue)->toBe('priority')
        ->and($page->batches[0]->queueExplicit)->toBeTrue()
        ->and($page->batches[0]->connectionExplicit)->toBeTrue()
        ->and($query->statusCounts($filters)->all)->toBe(1);

    $queueOnlyFilters = databaseBatchQueryFilters(queue: 'priority');
    $queueOnly = $query->page($queueOnlyFilters, null);

    expect(array_column($queueOnly->toArray()['batches'], 'id'))
        ->toContain('batch-026', 'batch-027')
        ->and($query->statusCounts($queueOnlyFilters)->all)->toBe(2);

    $inferredQueueFilters = databaseBatchQueryFilters(queue: 'default');
    $inferredConnectionFilters = databaseBatchQueryFilters(connection: 'redis');
    $inferredQueue = $query->page($inferredQueueFilters, null);
    $inferredConnection = $query->page($inferredConnectionFilters, null);
    $recent = $query->page(databaseBatchQueryFilters(
        created: BatchCreatedRange::LastHour,
    ), null);
    $idSearch = $query->page(databaseBatchQueryFilters(query: 'batch-026'), null);

    expect($query->statusCounts($inferredQueueFilters)->all)->toBe(98)
        ->and($inferredQueue->batches[0]->queueExplicit)->toBeFalse()
        ->and($inferredQueue->batches[0]->connectionExplicit)->toBeFalse()
        ->and($query->statusCounts($inferredConnectionFilters)->all)->toBe(100)
        ->and(array_column($inferredConnection->toArray()['batches'], 'id'))
        ->toContain('batch-101')
        ->and(array_column($recent->toArray()['batches'], 'id'))->toBe(['batch-101'])
        ->and(array_column($idSearch->toArray()['batches'], 'id'))->toBe(['batch-026']);
});

it('keeps source-column queries exact while destination filters wait for the metadata migration', function (): void {
    Schema::drop('zenith_batch_metadata');

    $query = databaseBatchQuery();
    $filters = databaseBatchQueryFilters(
        query: '100%_match',
        created: BatchCreatedRange::Last7Days,
        status: BatchStatus::Failures,
        sort: BatchSort::Name,
        direction: BatchSortDirection::Ascending,
    );
    $page = $query->page($filters, null);

    expect($query->supported())->toBeTrue()
        ->and($query->attributionSupported())->toBeFalse()
        ->and(array_column($page->toArray()['batches'], 'id'))->toBe(['batch-026'])
        ->and($query->statusCounts($filters)->all)->toBe(1)
        ->and(fn () => $query->page(
            databaseBatchQueryFilters(queue: 'priority'),
            null,
        ))->toThrow(
            RuntimeException::class,
            'Run the Zenith batch metadata migration',
        );
});

it('streams database clear candidates in bounded chunks without the metadata migration', function (): void {
    Schema::drop('zenith_batch_metadata');

    foreach (range(102, 1101) as $index) {
        databaseBatchQueryInsertBatch(
            id: sprintf('batch-%04d', $index),
            name: sprintf('Batch %04d', $index),
            total: 1,
            pending: 0,
            failed: 0,
            options: [],
            createdAt: Date::now()->getTimestamp(),
            finishedAt: Date::now()->getTimestamp(),
        );
    }

    $chunks = iterator_to_array(databaseBatchQuery()->clearCandidateChunks());

    expect(array_map(count(...), $chunks))->toBe([500, 500, 25])
        ->and(array_sum(array_map(count(...), $chunks)))->toBe(1025);
});

it('returns exact status counts and applies each status to the full retained set', function (): void {
    $query = databaseBatchQuery();
    $filters = databaseBatchQueryFilters();

    expect($query->statusCounts($filters)->toArray())->toBe([
        'all' => 101,
        'pending' => 26,
        'finished' => 25,
        'failures' => 25,
        'cancelled' => 25,
    ]);

    foreach (BatchStatus::cases() as $status) {
        $page = $query->page(databaseBatchQueryFilters(status: $status), null);

        expect($page->batches)->not->toBe([])
            ->and(array_unique(array_column($page->toArray()['batches'], 'status')))
            ->toBe([$status->value]);
    }
});

it('does not aggregate status counts while fetching a row page', function (): void {
    $queries = [];

    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $query = databaseBatchQuery();
    $filters = databaseBatchQueryFilters();
    $query->page($filters, null);

    expect(collect($queries)->contains(
        fn (string $sql): bool => str_contains($sql, 'group by')
            && str_contains($sql, 'status'),
    ))->toBeFalse();

    $counts = $query->statusCounts($filters);

    expect($counts->all)->toBe(101)
        ->and(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'group by')
                && str_contains($sql, 'status'),
        ))->toBeTrue();
});

it('sorts all six columns before stable 50-row keyset pagination', function (): void {
    $query = databaseBatchQuery();
    $sorts = [
        BatchSort::Name->value => static fn ($row): string => mb_strtolower($row->displayName),
        BatchSort::TotalJobs->value => static fn ($row): int => $row->totalJobs,
        BatchSort::PendingJobs->value => static fn ($row): int => $row->pendingJobs,
        BatchSort::FailedJobs->value => static fn ($row): int => $row->failedJobs,
        BatchSort::Progress->value => static fn ($row): int => $row->progress,
        BatchSort::CreatedAt->value => static fn ($row): int => $row->createdAt,
    ];

    foreach ($sorts as $sort => $value) {
        foreach (BatchSortDirection::cases() as $direction) {
            $filters = databaseBatchQueryFilters(
                sort: BatchSort::from($sort),
                direction: $direction,
            );
            $cursor = null;
            $rows = [];
            $firstPageCount = null;

            do {
                $page = $query->page($filters, $cursor);
                $firstPageCount ??= count($page->batches);
                array_push($rows, ...$page->batches);
                $cursor = $page->next;
            } while ($cursor !== null);

            $values = array_map($value, $rows);
            $expected = $values;

            $direction === BatchSortDirection::Ascending
                ? sort($expected)
                : rsort($expected);

            expect($firstPageCount)->toBe(50)
                ->and($rows)->toHaveCount(101)
                ->and(array_unique(array_column($rows, 'id')))->toHaveCount(101)
                ->and($values)->toBe($expected);
        }
    }
});

it('uses the batch id as a stable tie-break across a duplicate sort boundary', function (): void {
    app('db')->table('job_batches')->update([
        'created_at' => Date::now()->getTimestamp(),
    ]);

    $query = databaseBatchQuery();
    $ascendingIds = array_map(
        static fn (int $index): string => sprintf('batch-%03d', $index),
        range(1, 101),
    );

    foreach (BatchSortDirection::cases() as $direction) {
        $filters = databaseBatchQueryFilters(
            sort: BatchSort::CreatedAt,
            direction: $direction,
        );
        $first = $query->page($filters, null);
        $second = $query->page($filters, $first->next);
        $third = $query->page($filters, $second->next);
        $ids = [
            ...array_column($first->batches, 'id'),
            ...array_column($second->batches, 'id'),
            ...array_column($third->batches, 'id'),
        ];
        $expected = $direction === BatchSortDirection::Ascending
            ? $ascendingIds
            : array_reverse($ascendingIds);

        expect($first->batches)->toHaveCount(50)
            ->and($second->batches)->toHaveCount(50)
            ->and($third->batches)->toHaveCount(1)
            ->and($first->next)->not->toBeNull()
            ->and($second->next)->not->toBeNull()
            ->and($third->next)->toBeNull()
            ->and($ids)->toBe($expected)
            ->and(array_unique($ids))->toHaveCount(101);
    }
});

it('invalidates a cursor when any active filter changes', function (): void {
    $query = databaseBatchQuery();
    $firstPage = $query->page(databaseBatchQueryFilters(), null);

    expect($firstPage->next)->not->toBeNull();

    $recent = $query->page(
        databaseBatchQueryFilters(created: BatchCreatedRange::LastHour),
        $firstPage->next,
    );

    expect(array_column($recent->toArray()['batches'], 'id'))->toBe(['batch-101']);
});

it('reconciles new rows and source-rooted queries immediately hide deleted batches', function (): void {
    $query = databaseBatchQuery();

    expect($query->page(databaseBatchQueryFilters(query: 'late arrival'), null)->batches)->toBe([]);

    databaseBatchQueryInsertBatch(
        id: 'batch-102',
        name: 'Late arrival',
        total: 10,
        pending: 5,
        failed: 0,
        options: ['queue' => 'late', 'connection' => 'redis'],
        createdAt: Date::now()->getTimestamp(),
    );
    $query = databaseBatchQuery();

    expect(array_column(
        $query->page(databaseBatchQueryFilters(queue: 'late'), null)->toArray()['batches'],
        'id',
    ))->toBe(['batch-102']);

    app('db')->table('job_batches')->where('id', 'batch-102')->delete();
    $query = databaseBatchQuery();

    expect($query->page(databaseBatchQueryFilters(queue: 'late'), null)->batches)->toBe([]);
});

it('provides exact catalogs overviews and queue activity from the same source query', function (): void {
    $repository = databaseBatchQueryRepository();
    $capability = new DatabaseBatchCapability($repository);
    $query = new DatabaseBatchQuery(
        $capability,
        new DatabaseBatchMetadataSynchronizer($capability, app('config')),
    );
    $jobs = mockDashboardContract(JobRepository::class);
    $batches = new BatchesData(
        $repository,
        new BatchJobsData($jobs, new JobsData($jobs)),
        $query,
    );
    $queueBatches = new QueueBatchesData(
        $repository,
        $batches,
        app(CacheFactory::class),
        $query,
    );

    $catalog = $query->catalog();
    $overview = $query->overview();
    $summary = $queueBatches->summary('priority');
    $page = $queueBatches->page('priority', null);

    expect($catalog->queues)->toBe(['default', 'inferred-sqs', 'priority'])
        ->and($catalog->connections)->toBe(['redis', 'sqs'])
        ->and($overview['total'])->toBe(101)
        ->and($overview['complete'])->toBeTrue()
        ->and($summary->total)->toBe(2)
        ->and($summary->complete)->toBeTrue()
        ->and($page->total)->toBe(2)
        ->and(array_column($page->toArray()['rows'], 'id'))->toBe([
            'batch-026',
            'batch-027',
        ]);
});

it('previews dashboard active batches by progress descending with id tie-break', function (): void {
    app('db')->table('job_batches')->delete();

    foreach ([
        [
            'id' => 'preview-new-zero',
            'name' => 'Newest zero progress',
            'pending' => 100,
            'createdAt' => Date::now()->getTimestamp(),
        ],
        [
            'id' => 'preview-ten',
            'name' => 'Ten percent',
            'pending' => 90,
            'createdAt' => Date::now()->subMinutes(30)->getTimestamp(),
        ],
        [
            'id' => 'preview-mid-z',
            'name' => 'Thirty-five percent z',
            'pending' => 65,
            'createdAt' => Date::now()->subHour()->getTimestamp(),
        ],
        [
            'id' => 'preview-mid-a',
            'name' => 'Thirty-five percent a',
            'pending' => 65,
            'createdAt' => Date::now()->subHours(3)->getTimestamp(),
        ],
        [
            'id' => 'preview-old-high',
            'name' => 'Oldest high progress',
            'pending' => 15,
            'createdAt' => Date::now()->subHours(2)->getTimestamp(),
        ],
        [
            'id' => 'preview-finished',
            'name' => 'Finished batch',
            'pending' => 0,
            'createdAt' => Date::now()->subMinutes(5)->getTimestamp(),
            'finishedAt' => Date::now()->getTimestamp(),
        ],
    ] as $batch) {
        databaseBatchQueryInsertBatch(
            id: $batch['id'],
            name: $batch['name'],
            total: 100,
            pending: $batch['pending'],
            failed: 0,
            options: [],
            createdAt: $batch['createdAt'],
            finishedAt: $batch['finishedAt'] ?? null,
        );
    }

    $overview = databaseBatchQuery()->overview();

    expect($overview['total'])->toBe(6)
        ->and($overview['active'])->toBe(5)
        ->and($overview['complete'])->toBeTrue()
        ->and(array_column($overview['previews'], 'id'))->toBe([
            'preview-old-high',
            'preview-mid-z',
            'preview-mid-a',
        ])
        ->and(array_column($overview['previews'], 'progress'))->toBe([85, 35, 35]);
});

it('previews queue summary active batches by progress descending rather than newest first', function (): void {
    app('db')->table('job_batches')->delete();
    app('db')->table('zenith_batch_metadata')->delete();

    foreach ([
        [
            'id' => 'queue-preview-new-zero-a',
            'name' => 'Newest zero a',
            'pending' => 100,
            'createdAt' => Date::now()->getTimestamp(),
            'queue' => 'priority',
        ],
        [
            'id' => 'queue-preview-new-zero-b',
            'name' => 'Newest zero b',
            'pending' => 100,
            'createdAt' => Date::now()->subMinute()->getTimestamp(),
            'queue' => 'priority',
        ],
        [
            'id' => 'queue-preview-new-zero-c',
            'name' => 'Newest zero c',
            'pending' => 100,
            'createdAt' => Date::now()->subMinutes(2)->getTimestamp(),
            'queue' => 'priority',
        ],
        [
            'id' => 'queue-preview-old-progressing',
            'name' => 'Older progressing batch',
            'pending' => 60,
            'createdAt' => Date::now()->subHours(2)->getTimestamp(),
            'queue' => 'priority',
        ],
        [
            'id' => 'queue-preview-mid',
            'name' => 'Mid progress',
            'pending' => 70,
            'createdAt' => Date::now()->subHour()->getTimestamp(),
            'queue' => 'priority',
        ],
        [
            'id' => 'queue-preview-other-queue',
            'name' => 'Other queue high progress',
            'pending' => 10,
            'createdAt' => Date::now()->subMinutes(3)->getTimestamp(),
            'queue' => 'other',
        ],
        [
            'id' => 'queue-preview-finished',
            'name' => 'Finished priority',
            'pending' => 0,
            'createdAt' => Date::now()->subMinutes(4)->getTimestamp(),
            'finishedAt' => Date::now()->getTimestamp(),
            'queue' => 'priority',
        ],
    ] as $batch) {
        databaseBatchQueryInsertBatch(
            id: $batch['id'],
            name: $batch['name'],
            total: 100,
            pending: $batch['pending'],
            failed: 0,
            options: ['queue' => $batch['queue'], 'connection' => 'redis'],
            createdAt: $batch['createdAt'],
            finishedAt: $batch['finishedAt'] ?? null,
        );
    }

    $summary = databaseBatchQuery()->queueSummary('priority');

    expect($summary->total)->toBe(6)
        ->and($summary->active)->toBe(5)
        ->and($summary->complete)->toBeTrue()
        ->and(array_column($summary->toArray()['previews'], 'id'))->toBe([
            'queue-preview-old-progressing',
            'queue-preview-mid',
            'queue-preview-new-zero-c',
        ])
        ->and(array_column($summary->toArray()['previews'], 'progress'))->toBe([40, 30, 0]);
});

it('puts actively processing queue batches ahead of waiting and terminal batches', function (): void {
    foreach ([
        [
            'id' => 'queue-complete',
            'pending' => 0,
            'failed' => 0,
            'createdAt' => Date::now()->subMinute()->getTimestamp(),
            'finishedAt' => Date::now()->getTimestamp(),
        ],
        [
            'id' => 'queue-waiting',
            'pending' => 10,
            'failed' => 0,
            'createdAt' => Date::now()->subMinutes(2)->getTimestamp(),
        ],
        [
            'id' => 'queue-active-least',
            'pending' => 8,
            'failed' => 0,
            'createdAt' => Date::now()->subMinutes(3)->getTimestamp(),
        ],
        [
            'id' => 'queue-active-failed',
            'pending' => 5,
            'failed' => 1,
            'createdAt' => Date::now()->subMinutes(4)->getTimestamp(),
        ],
        [
            'id' => 'queue-active-most',
            'pending' => 2,
            'failed' => 0,
            'createdAt' => Date::now()->subMinutes(5)->getTimestamp(),
        ],
    ] as $batch) {
        databaseBatchQueryInsertBatch(
            id: $batch['id'],
            name: $batch['id'],
            total: 10,
            pending: $batch['pending'],
            failed: $batch['failed'],
            options: ['queue' => 'activity-order', 'connection' => 'redis'],
            createdAt: $batch['createdAt'],
            finishedAt: $batch['finishedAt'] ?? null,
        );
    }

    $repository = databaseBatchQueryRepository();
    $capability = new DatabaseBatchCapability($repository);
    $query = new DatabaseBatchQuery(
        $capability,
        new DatabaseBatchMetadataSynchronizer($capability, app('config')),
    );
    $jobs = mockDashboardContract(JobRepository::class);
    $page = (new QueueBatchesData(
        $repository,
        new BatchesData(
            $repository,
            new BatchJobsData($jobs, new JobsData($jobs)),
            $query,
        ),
        app(CacheFactory::class),
        $query,
    ))->page('activity-order', null);

    expect(array_column($page->toArray()['rows'], 'id'))->toBe([
        'queue-active-most',
        'queue-active-failed',
        'queue-active-least',
        'queue-waiting',
        'queue-complete',
    ]);
});

it('selects queue retry jobs from the immutable first-observed attribution snapshot', function (): void {
    app('db')->table('job_batches')->where('id', 'batch-001')->update([
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['inferred-default-job'], JSON_THROW_ON_ERROR),
    ]);
    app('db')->table('job_batches')->where('id', 'batch-026')->update([
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(
            ['explicit-priority-job', 'shared-job'],
            JSON_THROW_ON_ERROR,
        ),
    ]);
    app('db')->table('job_batches')->where('id', 'batch-027')->update([
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['shared-job'], JSON_THROW_ON_ERROR),
    ]);

    $query = databaseBatchQuery();

    expect(iterator_to_array($query->failedJobIdsForQueue('default'), false))->toBe([
        'inferred-default-job',
    ])->and(array_values(array_unique(iterator_to_array($query->failedJobIdsForQueue('priority'), false))))->toBe([
        'explicit-priority-job',
        'shared-job',
    ]);

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.queue', 'later-default');
    $query = databaseBatchQuery();

    expect(iterator_to_array($query->failedJobIdsForQueue('default'), false))->toBe([
        'inferred-default-job',
    ])->and(iterator_to_array($query->failedJobIdsForQueue('later-default'), false))->toBe([]);
});

function databaseBatchQuery(): DatabaseBatchQuery
{
    $repository = databaseBatchQueryRepository();
    $capability = new DatabaseBatchCapability($repository);

    return new DatabaseBatchQuery(
        $capability,
        new DatabaseBatchMetadataSynchronizer($capability, app('config')),
    );
}

function databaseBatchQueryRepository(): DatabaseBatchRepository
{
    return new DatabaseBatchRepository(
        app(BatchFactory::class),
        app('db')->connection(),
        'job_batches',
    );
}

function databaseBatchQueryFilters(
    ?string $query = null,
    ?string $queue = null,
    ?string $connection = null,
    ?BatchCreatedRange $created = null,
    ?BatchStatus $status = null,
    BatchSort $sort = BatchSort::CreatedAt,
    BatchSortDirection $direction = BatchSortDirection::Descending,
): BatchIndexFiltersData {
    return new BatchIndexFiltersData(
        query: $query,
        queue: $queue,
        connection: $connection,
        created: $created,
        status: $status,
        sort: $sort,
        direction: $direction,
    );
}

/** @param array<string, mixed> $options */
function databaseBatchQueryInsertBatch(
    string $id,
    string $name,
    int $total,
    int $pending,
    int $failed,
    array $options,
    int $createdAt,
    ?int $cancelledAt = null,
    ?int $finishedAt = null,
): void {
    app('db')->table('job_batches')->insert([
        'id' => $id,
        'name' => $name,
        'total_jobs' => $total,
        'pending_jobs' => $pending,
        'failed_jobs' => $failed,
        'failed_job_ids' => '[]',
        'options' => serialize($options),
        'cancelled_at' => $cancelledAt,
        'created_at' => $createdAt,
        'finished_at' => $finishedAt,
    ]);
}
