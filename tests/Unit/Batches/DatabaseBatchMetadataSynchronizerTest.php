<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchCapability;
use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchMetadataSynchronizer;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

use function DevactionLabs\HorizonNewDawn\Tests\Support\dashboardExpects;
use function DevactionLabs\HorizonNewDawn\Tests\Support\mockDashboardContract;

beforeEach(function (): void {
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', 'default');
    config()->set('queue.connections.sqs.queue', 'inferred-sqs');

    Schema::dropIfExists('horizon_new_dawn_batch_metadata');
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

    $migration = require __DIR__.'/../../../database/migrations/2026_07_26_000000_create_horizon_new_dawn_batch_metadata_table.php';
    $migration->up();
});

afterEach(function (): void {
    Schema::dropIfExists('horizon_new_dawn_batch_metadata');
    Schema::dropIfExists('job_batches');
});

it('captures immutable explicit and inferred attribution once and reconciles every missing batch', function (): void {
    foreach (range(1, 101) as $index) {
        databaseBatchMetadataInsertBatch(
            sprintf('batch-%03d', $index),
            match ($index) {
                1 => ['queue' => 'imports', 'connection' => 'redis'],
                2 => ['connection' => 'sqs'],
                default => [],
            },
        );
    }

    $synchronizer = databaseBatchMetadataSynchronizer();

    expect($synchronizer->sync())->toBe(101)
        ->and(app('db')->table('horizon_new_dawn_batch_metadata')->count())->toBe(101)
        ->and((array) app('db')->table('horizon_new_dawn_batch_metadata')->where('batch_id', 'batch-001')->first())
        ->toMatchArray([
            'batch_id' => 'batch-001',
            'queue' => 'imports',
            'connection' => 'redis',
            'queue_is_explicit' => 1,
            'connection_is_explicit' => 1,
        ])
        ->and((array) app('db')->table('horizon_new_dawn_batch_metadata')->where('batch_id', 'batch-002')->first())
        ->toMatchArray([
            'batch_id' => 'batch-002',
            'queue' => 'inferred-sqs',
            'connection' => 'sqs',
            'queue_is_explicit' => 0,
            'connection_is_explicit' => 1,
        ])
        ->and((array) app('db')->table('horizon_new_dawn_batch_metadata')->where('batch_id', 'batch-003')->first())
        ->toMatchArray([
            'batch_id' => 'batch-003',
            'queue' => 'default',
            'connection' => 'redis',
            'queue_is_explicit' => 0,
            'connection_is_explicit' => 0,
        ]);

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.queue', 'later-default');
    app('db')->table('job_batches')->where('id', 'batch-001')->update([
        'options' => serialize(['queue' => 'changed', 'connection' => 'database']),
    ]);
    databaseBatchMetadataInsertBatch('batch-102', ['queue' => 'reports']);
    databaseBatchMetadataInsertBatch('batch-103', []);
    $synchronizer = databaseBatchMetadataSynchronizer();

    expect($synchronizer->sync())->toBe(2)
        ->and((array) app('db')->table('horizon_new_dawn_batch_metadata')->where('batch_id', 'batch-001')->first())
        ->toMatchArray([
            'queue' => 'imports',
            'connection' => 'redis',
        ])
        ->and((array) app('db')->table('horizon_new_dawn_batch_metadata')->where('batch_id', 'batch-003')->first())
        ->toMatchArray([
            'queue' => 'default',
            'connection' => 'redis',
            'queue_is_explicit' => 0,
            'connection_is_explicit' => 0,
        ])
        ->and((array) app('db')->table('horizon_new_dawn_batch_metadata')->where('batch_id', 'batch-102')->first())
        ->toMatchArray([
            'queue' => 'reports',
            'connection' => 'database',
            'queue_is_explicit' => 1,
            'connection_is_explicit' => 0,
        ])
        ->and((array) app('db')->table('horizon_new_dawn_batch_metadata')->where('batch_id', 'batch-103')->first())
        ->toMatchArray([
            'queue' => 'later-default',
            'connection' => 'database',
            'queue_is_explicit' => 0,
            'connection_is_explicit' => 0,
        ]);

    app('db')->table('job_batches')->where('id', 'batch-001')->delete();
    $staleRows = array_map(
        static fn (int $index): array => [
            'batch_id' => sprintf('stale-%03d', $index),
            'queue' => 'default',
            'connection' => 'redis',
            'queue_is_explicit' => false,
            'connection_is_explicit' => false,
        ],
        range(1, 501),
    );
    app('db')->table('horizon_new_dawn_batch_metadata')->insert($staleRows);
    $synchronizer = databaseBatchMetadataSynchronizer();

    expect($synchronizer->sync())->toBe(0)
        ->and(app('db')->table('horizon_new_dawn_batch_metadata')
            ->where('batch_id', 'batch-001')
            ->exists())->toBeFalse()
        ->and(app('db')->table('horizon_new_dawn_batch_metadata')
            ->where('batch_id', 'like', 'stale-%')
            ->count())->toBe(0)
        ->and(app('db')->table('horizon_new_dawn_batch_metadata')->count())
        ->toBe(app('db')->table('job_batches')->count());
});

it('reconciles at most once for one request-scoped synchronizer', function (): void {
    databaseBatchMetadataInsertBatch('batch-001', ['queue' => 'imports']);
    $synchronizer = databaseBatchMetadataSynchronizer();

    expect($synchronizer->sync())->toBe(1);

    $connection = app('db')->connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    expect($synchronizer->sync())->toBe(0)
        ->and($connection->getQueryLog())->toBe([]);
});

it('rejects custom repositories without resolving a database connection or schema', function (): void {
    config()->set('queue.batching.database', 'connection-that-must-not-be-resolved');

    $capability = new DatabaseBatchCapability(
        mockDashboardContract(BatchRepository::class),
    );

    expect($capability->supported())->toBeFalse()
        ->and($capability->attributionSupported())->toBeFalse()
        ->and($capability->message())->toBe(
            'Exact retained batch queries require Laravel\'s database batch repository.',
        )
        ->and($capability->attributionMessage())->toBe(
            'Queue and connection attribution require Laravel\'s database batch repository.',
        );
});

it('supports database batch repository subclasses', function (): void {
    $repository = new class(app(BatchFactory::class), app('db')->connection(), 'job_batches') extends DatabaseBatchRepository {};
    $capability = new DatabaseBatchCapability($repository);

    expect($capability->supported())->toBeTrue()
        ->and($capability->message())->toBeNull();
});

it('rejects database drivers without verified exact query support after confirming the source table', function (): void {
    $schema = mockDashboardContract(Builder::class);
    dashboardExpects($schema, 'hasTable', ['job_batches'], value: true);
    dashboardExpects($schema, 'hasTable', ['horizon_new_dawn_batch_metadata'], times: 'never');
    $connection = mockDashboardContract(Connection::class);
    dashboardExpects($connection, 'getDriverName', value: 'sqlsrv');
    dashboardExpects($connection, 'getSchemaBuilder', value: $schema);
    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        $connection,
        'job_batches',
    );
    $capability = new DatabaseBatchCapability($repository);

    expect($capability->available())->toBeTrue()
        ->and($capability->supported())->toBeFalse()
        ->and($capability->attributionSupported())->toBeFalse()
        ->and($capability->capability()->toArray())->toBe([
            'supported' => false,
            'message' => 'The configured batch database driver is not supported for exact filters and sorting.',
            'attributionSupported' => false,
            'attributionMessage' => 'The configured batch database driver is not supported for queue and connection attribution.',
        ])
        ->and($capability->message())->toBe(
            'The configured batch database driver is not supported for exact filters and sorting.',
        );
});

it('inspects supported database schema capability once per scoped instance', function (): void {
    $schema = mockDashboardContract(Builder::class);
    dashboardExpects($schema, 'hasTable', ['job_batches'], value: true);
    dashboardExpects(
        $schema,
        'hasTable',
        ['horizon_new_dawn_batch_metadata'],
        value: true,
    );
    $connection = mockDashboardContract(Connection::class);
    dashboardExpects($connection, 'getDriverName', value: 'sqlite');
    dashboardExpects($connection, 'getSchemaBuilder', value: $schema);
    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        $connection,
        'job_batches',
    );
    $capability = new DatabaseBatchCapability($repository);

    expect($capability->supported())->toBeTrue()
        ->and($capability->attributionSupported())->toBeTrue()
        ->and($capability->capability()->toArray())->toBe([
            'supported' => true,
            'message' => null,
            'attributionSupported' => true,
            'attributionMessage' => null,
        ])
        ->and($capability->message())->toBeNull();
});

it('reports the exact missing database capability', function (): void {
    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        app('db')->connection(),
        'job_batches',
    );
    $capability = new DatabaseBatchCapability($repository);

    Schema::drop('horizon_new_dawn_batch_metadata');

    expect($capability->supported())->toBeTrue()
        ->and($capability->message())->toBeNull()
        ->and($capability->attributionSupported())->toBeFalse()
        ->and($capability->attributionMessage())->toBe(
            'Run the Horizon New Dawn batch metadata migration to enable queue and connection attribution.',
        );

    Schema::drop('job_batches');
    $capability = new DatabaseBatchCapability($repository);

    expect($capability->message())->toBe(
        'The configured Laravel batch table is unavailable.',
    );
});

function databaseBatchMetadataSynchronizer(): DatabaseBatchMetadataSynchronizer
{
    $connection = app('db')->connection();
    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        $connection,
        'job_batches',
    );
    $capability = new DatabaseBatchCapability($repository);

    return new DatabaseBatchMetadataSynchronizer($capability, app('config'));
}

/** @param array<string, mixed> $options */
function databaseBatchMetadataInsertBatch(string $id, array $options): void
{
    app('db')->table('job_batches')->insert([
        'id' => $id,
        'name' => "Batch {$id}",
        'total_jobs' => 10,
        'pending_jobs' => 5,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => serialize($options),
        'cancelled_at' => null,
        'created_at' => 1_784_281_000,
        'finished_at' => null,
    ]);
}
