<?php

declare(strict_types=1);

use DevactionLabs\Zenith\BulkOperations\Jobs\RetryQueueBatchesJob;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Horizon;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

/** @param 'never'|'once'|'twice'|'zeroOrMoreTimes' $times */
function bindQueueBatchRetryAsyncBulkQueue(string $times = 'once'): void
{
    config()->set('zenith.bulk_operations.connection', 'operations');
    config()->set('zenith.bulk_operations.queue', 'horizon-maintenance');

    $manager = Mockery::mock(QueueManager::class);
    dashboardExpects($manager, 'connection', ['operations'], times: $times, value: Mockery::mock(Queue::class));
    app()->instance(QueueManager::class, $manager);
}

function bindQueueBatchRetrySyncBulkQueue(): void
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
    config()->set('queue.batching.table', 'job_batches');

    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');
    Schema::create('job_batches', function (Blueprint $table): void {
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
    $migration = require __DIR__.'/../../database/migrations/2026_07_26_000000_create_zenith_batch_metadata_table.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new LogicException('Expected the batch metadata migration to define an up method.');
    }

    $migration->up();

    app()->instance(BatchRepository::class, new DatabaseBatchRepository(
        app(BatchFactory::class),
        app('db')->connection(),
        'job_batches',
    ));
});

afterEach(function (): void {
    Horizon::auth(static fn (): bool => true);
    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');
});

it('queues retrying failed jobs from retained batches for the selected queue', function (): void {
    Bus::fake();
    bindQueueBatchRetryAsyncBulkQueue();

    post('/horizon/queues/reports/batches/retry-failed-jobs')
        ->assertRedirect()
        ->assertSessionHas('toast.success', 'Retrying failed batch jobs from reports was queued.');

    Bus::assertDispatched(
        RetryQueueBatchesJob::class,
        fn (RetryQueueBatchesJob $job): bool => $job->queueName === 'reports'
            && $job->connection === 'operations'
            && $job->queue === 'horizon-maintenance',
    );
    Bus::assertDispatchedTimes(RetryQueueBatchesJob::class, 1);
});

it('reports when a queue batch retry cannot use an asynchronous bulk queue', function (): void {
    Bus::fake();
    Exceptions::fake();
    bindQueueBatchRetrySyncBulkQueue();

    post('/horizon/queues/reports/batches/retry-failed-jobs')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.error',
            'The bulk operation could not be queued. Check the application logs and try again.',
        );

    Bus::assertNothingDispatched();
    Exceptions::assertReportedCount(1);
});

it('queues a queue batch retry when the scoped failures exceed the former ceiling', function (): void {
    Bus::fake();
    bindQueueBatchRetryAsyncBulkQueue();

    DB::table('job_batches')->insert([
        'id' => 'batch-1',
        'name' => 'Import reports',
        'total_jobs' => 2,
        'pending_jobs' => 0,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2'], JSON_THROW_ON_ERROR),
        'options' => serialize([
            'connection' => 'redis',
            'queue' => 'reports',
        ]),
        'created_at' => 1_784_281_000,
    ]);

    post('/horizon/queues/reports/batches/retry-failed-jobs')
        ->assertRedirect()
        ->assertSessionHas(
            'toast.success',
            'Retrying failed batch jobs from reports was queued.',
        );

    Bus::assertDispatched(RetryQueueBatchesJob::class);
});

it('hides direct queue batch retries until attribution storage is migrated', function (): void {
    Bus::fake();
    Schema::drop('zenith_batch_metadata');

    post('/horizon/queues/reports/batches/retry-failed-jobs')->assertNotFound();

    Bus::assertNothingDispatched();
});
