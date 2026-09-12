<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Batches\Actions\ClearBatches;
use DevactionLabs\Zenith\Batches\BatchClearScope;
use DevactionLabs\Zenith\Batches\ClearableBatches;
use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\BulkOperations\Jobs\ClearBatchesJob;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Horizon;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardNeverReceives;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\horizonJob;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;
use function Pest\Laravel\delete;
use function Pest\Laravel\withoutMiddleware;

function bindBatchClearAsyncBulkQueue(): void
{
    config()->set('zenith.bulk_operations.connection', 'operations');
    config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

function bindBatchClearSyncBulkQueue(): void
{
    config()->set('zenith.bulk_operations.connection', 'sync');
    config()->set('zenith.bulk_operations.queue', null);

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['sync'], value: new SyncQueue);
    app()->instance(QueueManager::class, $manager);
}

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'zenith_batch_clearing');

    Schema::dropIfExists(DatabaseBatchCapability::METADATA_TABLE);
    Schema::dropIfExists('zenith_batch_clearing');
    Schema::create('zenith_batch_clearing', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->text('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });

    $now = now()->getTimestamp();

    DB::table('zenith_batch_clearing')->insert([
        batchClearRow('complete', totalJobs: 1, pendingJobs: 0, finishedAt: $now),
        batchClearRow('incomplete', failedJobs: 1, failedJobIds: ['failed-1']),
        batchClearRow('cancelled', pendingJobs: 0, cancelledAt: $now, finishedAt: $now),
        batchClearRow('active', totalJobs: 2, pendingJobs: 1),
        batchClearRow('retry-live', failedJobs: 1, failedJobIds: ['failed-2']),
        batchClearRow('cancelled-live', cancelledAt: $now, finishedAt: $now),
        batchClearRow('cancelled-counted-active', cancelledAt: $now, finishedAt: $now),
    ]);

    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        DB::connection(),
        'zenith_batch_clearing',
    );
    $jobs = mockDashboardContract(JobRepository::class);
    dashboardNeverReceives($jobs, 'countPending');
    dashboardNeverReceives($jobs, 'getPending');
    $retryingParent = horizonJob(1, 'failed-2');
    $retryingParent->retried_by = json_encode([
        ['id' => 'retry-live-job', 'status' => 'pending'],
    ], JSON_THROW_ON_ERROR);
    dashboardReturnsUsing(
        $jobs,
        'getJobs',
        static function (array $failedJobIds) use ($retryingParent): Collection {
            expect($failedJobIds)->not->toBeEmpty()
                ->and(count($failedJobIds))->toBeLessThanOrEqual(500)
                ->and(array_diff(
                    $failedJobIds,
                    ['failed-1', 'failed-2', 'missing-failed-job'],
                ))->toBe([]);

            return new Collection(array_values(array_filter(
                [horizonJob(0, 'failed-1'), $retryingParent],
                static fn (object $job): bool => in_array($job->id, $failedJobIds, true),
            )));
        },
    );

    app()->instance(BatchRepository::class, $repository);
    app()->instance(JobRepository::class, $jobs);
});

afterEach(function (): void {
    Schema::dropIfExists(DatabaseBatchCapability::METADATA_TABLE);
    Schema::dropIfExists('zenith_batch_clearing');
    (require dirname(__DIR__, 2).'/database/migrations/2026_07_26_000000_create_zenith_batch_metadata_table.php')->up();
    Horizon::auth(static fn (): bool => true);
});

it('counts safely clearable database batches without the attribution migration', function (): void {
    expect(Schema::hasTable(DatabaseBatchCapability::METADATA_TABLE))->toBeFalse();

    $counts = app(ClearableBatches::class)->counts();

    expect($counts->complete)->toBe(1)
        ->and($counts->incomplete)->toBe(1)
        ->and($counts->cancelled)->toBe(1)
        ->and($counts->finished)->toBe(2)
        ->and($counts->completeScan)->toBeTrue();
});

it('excludes database batches with active or unverifiable Horizon retries', function (): void {
    DB::table('zenith_batch_clearing')->insert(
        batchClearRow(
            'missing-retry-parent',
            failedJobs: 1,
            failedJobIds: ['missing-failed-job'],
        ),
    );

    expect(app(ClearableBatches::class)->ids(BatchClearScope::Incomplete))
        ->toBe(['incomplete']);
});

it('clears the requested batch scope without deleting in-progress batches', function (
    string $scope,
    int $cleared,
    array $remaining,
): void {
    expect(app(ClearBatches::class)->handle(BatchClearScope::from($scope)))->toBe($cleared);

    expect(DB::table('zenith_batch_clearing')->orderBy('id')->pluck('id')->all())
        ->toBe($remaining);
})->with([
    'complete' => [
        'complete',
        1,
        [
            'active',
            'cancelled',
            'cancelled-counted-active',
            'cancelled-live',
            'incomplete',
            'retry-live',
        ],
    ],
    'incomplete' => [
        'incomplete',
        1,
        [
            'active',
            'cancelled',
            'cancelled-counted-active',
            'cancelled-live',
            'complete',
            'retry-live',
        ],
    ],
    'cancelled' => [
        'cancelled',
        1,
        ['active', 'cancelled-counted-active', 'cancelled-live', 'complete', 'incomplete', 'retry-live'],
    ],
    'finished' => [
        'finished',
        2,
        ['active', 'cancelled', 'cancelled-counted-active', 'cancelled-live', 'retry-live'],
    ],
]);

it('rejects unsupported batch clearing scopes', function (): void {
    delete('/horizon/batches/everything')->assertMethodNotAllowed();
});

it('classifies more than one thousand database batches without a scan ceiling', function (): void {
    foreach (array_chunk(range(1, 1001), 100) as $indexes) {
        DB::table('zenith_batch_clearing')->insert(array_map(
            static fn (int $index): array => batchClearRow(
                sprintf('bulk-complete-%04d', $index),
                pendingJobs: 0,
                finishedAt: now()->getTimestamp(),
            ),
            $indexes,
        ));
    }

    $counts = app(ClearableBatches::class)->counts();

    expect($counts->complete)->toBe(1002)
        ->and($counts->incomplete)->toBe(1)
        ->and($counts->cancelled)->toBe(1)
        ->and($counts->finished)->toBe(1003)
        ->and($counts->completeScan)->toBeTrue()
        ->and($counts->message)->toBeNull();
});

it('queues an oversized batch clear as background work', function (): void {
    Bus::fake();
    bindBatchClearAsyncBulkQueue();

    delete('/horizon/batches/finished')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.success',
            'Clearing finished batches was queued.',
        );

    Bus::assertDispatched(ClearBatchesJob::class);
});

it('fails closed when batch clear availability cannot be verified', function (): void {
    Bus::fake();
    Exceptions::fake();
    Schema::drop('zenith_batch_clearing');

    delete('/horizon/batches/finished')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'Batch clearing availability could not be verified.',
        );

    Bus::assertNothingDispatched();
    Exceptions::assertReportedCount(1);
});

it('deletes clearable batches in bounded transactions', function (): void {
    foreach (array_chunk(range(1, 200), 100) as $indexes) {
        DB::table('zenith_batch_clearing')->insert(array_map(
            static fn (int $index): array => batchClearRow(
                sprintf('transaction-complete-%03d', $index),
                pendingJobs: 0,
                finishedAt: now()->getTimestamp(),
            ),
            $indexes,
        ));
    }

    $transactions = [
        'begun' => 0,
        'committed' => 0,
    ];

    Event::listen(
        TransactionBeginning::class,
        static function () use (&$transactions): void {
            $transactions['begun']++;
        },
    );
    Event::listen(
        TransactionCommitted::class,
        static function () use (&$transactions): void {
            $transactions['committed']++;
        },
    );

    expect(app(ClearBatches::class)->handle(BatchClearScope::Complete))->toBe(201)
        ->and($transactions)->toBe([
            'begun' => 3,
            'committed' => 3,
        ]);
});

it('queues clearing the requested batch scope and honors Horizon authorization', function (): void {
    Bus::fake();
    bindBatchClearAsyncBulkQueue();

    delete('/horizon/batches/finished')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Clearing finished batches was queued.');

    Bus::assertDispatched(
        ClearBatchesJob::class,
        fn (ClearBatchesJob $job): bool => $job->scope === BatchClearScope::Finished
            && $job->connection === 'operations'
            && $job->queue === 'horizon-maintenance',
    );
    Bus::assertDispatchedTimes(ClearBatchesJob::class, 1);

    Horizon::auth(static fn (): bool => false);

    delete('/horizon/batches/'.BatchClearScope::Finished->value)->assertForbidden();
});

it('reports when clearing batches cannot use an asynchronous bulk queue', function (): void {
    Bus::fake();
    Exceptions::fake();
    bindBatchClearSyncBulkQueue();

    delete('/horizon/batches/finished')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'The bulk operation could not be queued. Check the application logs and try again.',
        );

    Bus::assertNothingDispatched();
    Exceptions::assertReportedCount(1);
});

/**
 * @param  array<int, string>  $failedJobIds
 * @return array<string, mixed>
 */
function batchClearRow(
    string $id,
    int $totalJobs = 1,
    int $pendingJobs = 1,
    int $failedJobs = 0,
    array $failedJobIds = [],
    ?int $cancelledAt = null,
    ?int $finishedAt = null,
): array {
    return [
        'id' => $id,
        'name' => $id,
        'total_jobs' => $totalJobs,
        'pending_jobs' => $pendingJobs,
        'failed_jobs' => $failedJobs,
        'failed_job_ids' => json_encode($failedJobIds, JSON_THROW_ON_ERROR),
        'options' => serialize([]),
        'cancelled_at' => $cancelledAt,
        'created_at' => now()->subHour()->getTimestamp(),
        'finished_at' => $finishedAt,
    ];
}
