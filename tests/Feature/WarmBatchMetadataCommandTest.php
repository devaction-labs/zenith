<?php

declare(strict_types=1);

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config()->set('queue.batching.database', null);
    config()->set('queue.batching.table', 'job_batches');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', 'default');

    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');
    Schema::create('job_batches', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->mediumText('options')->nullable();
    });

    app()->instance(BatchRepository::class, new DatabaseBatchRepository(
        app(BatchFactory::class),
        app('db')->connection(),
        'job_batches',
    ));
});

afterEach(function (): void {
    Schema::dropIfExists('zenith_batch_metadata');
    Schema::dropIfExists('job_batches');
});

it('warms first-observed destination metadata before operator requests', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_07_26_000000_create_zenith_batch_metadata_table.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new LogicException('Expected the batch metadata migration to define an up method.');
    }

    $migration->up();
    app('db')->table('job_batches')->insert([
        'id' => 'batch-001',
        'options' => serialize([]),
    ]);

    expect(Artisan::call('zenith:warm-batch-metadata'))->toBe(0)
        ->and(Artisan::output())->toContain('Batch destination metadata is warm')
        ->and((array) app('db')
            ->table('zenith_batch_metadata')
            ->where('batch_id', 'batch-001')
            ->first())->toMatchArray([
                'queue' => 'default',
                'connection' => 'redis',
                'queue_is_explicit' => 0,
                'connection_is_explicit' => 0,
            ]);
});

it('fails clearly until the destination metadata migration has run', function (): void {
    expect(Artisan::call('zenith:warm-batch-metadata'))->toBe(1)
        ->and(Artisan::output())->toContain(
            'Run the Zenith batch metadata migration',
        );
});

it('registers the warm batch metadata command with Artisan', function (): void {
    expect(Artisan::all())->toHaveKey('zenith:warm-batch-metadata');
});
