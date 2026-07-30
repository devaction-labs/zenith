<?php

declare(strict_types=1);

use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationMissingStateException;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;

use function NckRtl\HorizonNewDawn\Tests\Support\bulkSnapshot;
use function NckRtl\HorizonNewDawn\Tests\Support\bulkSnapshotRedis;

it('copies a point-in-time sorted set without serializing members into PHP', function (): void {
    $redis = bulkSnapshotRedis();
    $redis->seedSortedSet('failed_jobs', [
        'failed-a' => -3.0,
        'failed-b' => -2.0,
        'failed-c' => -1.0,
    ]);

    $operationId = bulkSnapshot()->createFromSortedSet('failed_jobs');

    $redis->seedSortedSet('failed_jobs', [
        'failed-a' => -3.0,
        'failed-b' => -2.0,
        'failed-c' => -1.0,
        'failed-after-boundary' => -0.5,
    ]);

    $first = bulkSnapshot()->nextChunk($operationId);

    expect($first)->toBe(['failed-a', 'failed-b', 'failed-c'])
        ->and($first)->not->toContain('failed-after-boundary');

    bulkSnapshot()->acknowledge($operationId, ...$first);

    expect(bulkSnapshot()->hasMore($operationId))->toBeFalse();
});

it('never returns more than the package chunk size and peels remaining work after acknowledge', function (): void {
    $redis = bulkSnapshotRedis();
    $members = [];

    for ($index = 0; $index < BulkOperationSnapshot::CHUNK_SIZE + 25; $index++) {
        $members["failed-{$index}"] = (float) -$index;
    }

    $redis->seedSortedSet('failed_jobs', $members);
    $operationId = bulkSnapshot()->createFromSortedSet('failed_jobs');

    $first = bulkSnapshot()->nextChunk($operationId);

    expect($first)->toHaveCount(BulkOperationSnapshot::CHUNK_SIZE)
        ->and(bulkSnapshot()->hasMore($operationId))->toBeTrue();

    bulkSnapshot()->acknowledge($operationId, ...$first);
    $second = bulkSnapshot()->nextChunk($operationId);

    expect($second)->toHaveCount(25)
        ->and(array_intersect($first, $second))->toBe([])
        ->and(bulkSnapshot()->hasMore($operationId))->toBeTrue();

    bulkSnapshot()->acknowledge($operationId, ...$second);

    expect(bulkSnapshot()->hasMore($operationId))->toBeFalse();
});

it('does not shift or skip remaining snapshot members when earlier live members disappear', function (): void {
    $redis = bulkSnapshotRedis();
    $redis->seedSortedSet('failed_jobs', [
        'failed-1' => -3.0,
        'failed-2' => -2.0,
        'failed-3' => -1.0,
    ]);

    $operationId = bulkSnapshot()->createFromSortedSet('failed_jobs');

    $redis->seedSortedSet('failed_jobs', [
        'failed-2' => -2.0,
        'failed-3' => -1.0,
    ]);

    expect(bulkSnapshot()->nextChunk($operationId))
        ->toBe(['failed-1', 'failed-2', 'failed-3']);
});

it('keeps unacknowledged members when a chunk is interrupted before completion', function (): void {
    $redis = bulkSnapshotRedis();
    $members = [];

    for ($index = 0; $index < BulkOperationSnapshot::CHUNK_SIZE + 5; $index++) {
        $members["failed-{$index}"] = (float) -$index;
    }

    $redis->seedSortedSet('failed_jobs', $members);
    $operationId = bulkSnapshot()->createFromSortedSet('failed_jobs');

    $first = bulkSnapshot()->nextChunk($operationId);
    $processed = array_slice($first, 0, 40);
    bulkSnapshot()->acknowledge($operationId, ...$processed);

    $replay = bulkSnapshot()->nextChunk($operationId);
    $remainingAfterPartialAck = (BulkOperationSnapshot::CHUNK_SIZE + 5) - 40;

    expect($first)->toHaveCount(BulkOperationSnapshot::CHUNK_SIZE)
        ->and($replay)->toHaveCount(min(BulkOperationSnapshot::CHUNK_SIZE, $remainingAfterPartialAck))
        ->and(array_slice($first, 40))->toEqual(array_slice($replay, 0, BulkOperationSnapshot::CHUNK_SIZE - 40))
        ->and(array_intersect($processed, $replay))->toBe([]);
});

it('renews temporary keys while active and removes them at completion', function (): void {
    $redis = bulkSnapshotRedis();
    $redis->seedSortedSet('failed_jobs', ['failed-1' => -1.0]);

    $operationId = bulkSnapshot()->createFromSortedSet('failed_jobs');
    $keys = [
        "\x1fhorizon-new-dawn:v1:bulk-op:{$operationId}:targets",
        "\x1fhorizon-new-dawn:v1:bulk-op:{$operationId}:meta",
    ];

    foreach ($keys as $key) {
        expect($redis->ttl($key))->toBe(BulkOperationSnapshot::IDLE_TTL_SECONDS);
    }

    $redis->expire($keys[0], 12);
    bulkSnapshot()->renew($operationId);

    foreach ($keys as $key) {
        expect($redis->ttl($key))->toBe(BulkOperationSnapshot::IDLE_TTL_SECONDS);
    }

    $ids = bulkSnapshot()->nextChunk($operationId);
    bulkSnapshot()->acknowledge($operationId, ...$ids);
    $total = bulkSnapshot()->finish($operationId);

    expect($total)->toBe(0);

    foreach ($keys as $key) {
        expect($redis->exists($key))->toBe(0);
    }
});

it('fails closed when snapshot state is missing or expired', function (): void {
    bulkSnapshotRedis();

    expect(fn () => bulkSnapshot()->nextChunk(str_repeat('a', 32)))
        ->toThrow(BulkOperationMissingStateException::class);
});

it('does not impose a one-hour idle ceiling constant', function (): void {
    expect(BulkOperationSnapshot::IDLE_TTL_SECONDS)->toBeGreaterThan(3600);
});
