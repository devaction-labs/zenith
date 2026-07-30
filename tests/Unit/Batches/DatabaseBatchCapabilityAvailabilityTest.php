<?php

declare(strict_types=1);

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use NckRtl\HorizonNewDawn\Batches\DatabaseBatchCapability;

use function NckRtl\HorizonNewDawn\Tests\Support\dashboardExpects;
use function NckRtl\HorizonNewDawn\Tests\Support\mockDashboardContract;

beforeEach(function (): void {
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    Schema::dropIfExists('horizon_new_dawn_batch_metadata');
    Schema::dropIfExists('job_batches');
});

afterEach(function (): void {
    Schema::dropIfExists('horizon_new_dawn_batch_metadata');
    Schema::dropIfExists('job_batches');
});

it('marks a database repository unavailable when the configured batch table is missing', function (): void {
    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        app('db')->connection(),
        'job_batches',
    );
    $capability = new DatabaseBatchCapability($repository);

    expect($capability->available())->toBeFalse()
        ->and($capability->supported())->toBeFalse()
        ->and($capability->storageMessage())->toContain('php artisan make:queue-batches-table')
        ->and($capability->storageMessage())->toContain('php artisan migrate');
});

it('marks a database repository available when the configured batch table exists', function (): void {
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

    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        app('db')->connection(),
        'job_batches',
    );
    $capability = new DatabaseBatchCapability($repository);

    expect($capability->available())->toBeTrue()
        ->and($capability->storageMessage())->toBeNull();
});

it('keeps non-database repositories available without a relational batch table', function (): void {
    $repository = mockDashboardContract(BatchRepository::class);
    $capability = new DatabaseBatchCapability($repository);

    expect($capability->available())->toBeTrue()
        ->and($capability->supported())->toBeFalse()
        ->and($capability->storageMessage())->toBeNull();
});

it('marks an unsupported SQL driver unavailable when the configured batch table is missing', function (): void {
    $schema = mockDashboardContract(Builder::class);
    dashboardExpects($schema, 'hasTable', ['job_batches'], value: false);
    dashboardExpects($schema, 'hasTable', ['horizon_new_dawn_batch_metadata'], times: 'never');
    $connection = mockDashboardContract(Connection::class);
    dashboardExpects($connection, 'getDriverName', times: 'never');
    dashboardExpects($connection, 'getSchemaBuilder', value: $schema);
    $repository = new DatabaseBatchRepository(
        app(BatchFactory::class),
        $connection,
        'job_batches',
    );
    $capability = new DatabaseBatchCapability($repository);

    expect($capability->available())->toBeFalse()
        ->and($capability->supported())->toBeFalse()
        ->and($capability->storageMessage())->toContain('php artisan make:queue-batches-table')
        ->and($capability->message())->toBe('The configured Laravel batch table is unavailable.');
});

it('keeps an unsupported SQL driver available when the configured batch table exists', function (): void {
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
        ->and($capability->storageMessage())->toBeNull()
        ->and($capability->message())->toBe(
            'The configured batch database driver is not supported for exact filters and sorting.',
        );
});
