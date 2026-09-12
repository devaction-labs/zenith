<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Browser suites use RefreshDatabase. Package batch-metadata migrations target
 * queue.batching.database (env DB_CONNECTION → sqlite). Testbench falls back to
 * an in-memory "testing" default when database.sqlite is absent at bootstrap,
 * but the batching connection still points at the file path — so CI without a
 * pre-created file must initialize that sqlite database before migrations run.
 */
it('initializes the sqlite database file and migrates package batch metadata', function (): void {
    $batchConnection = config('queue.batching.database');
    $connection = is_string($batchConnection) && $batchConnection !== ''
        ? $batchConnection
        : (string) config('database.default');

    $database = config("database.connections.{$connection}.database");

    expect($connection)->toBe('sqlite')
        ->and($database)->toBeString()
        ->and(
            $database === ':memory:'
            || str_contains($database, 'mode=memory')
            || is_file($database),
        )->toBeTrue()
        ->and(Schema::connection($connection)->hasTable('zenith_batch_metadata'))
        ->toBeTrue();
});
