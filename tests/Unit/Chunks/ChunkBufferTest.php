<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Chunks\ChunkBuffer;
use DevactionLabs\Zenith\Tests\Unit\Signals\InterleavingArrayStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ChunkRecorderJob implements ShouldQueue
{
    use Queueable;

    /** @var list<list<array<string, mixed>>> */
    public static array $chunks = [];

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function handle(array $items): void
    {
        self::$chunks[] = $items;
    }
}

/**
 * @return list<Closure(): void>
 */
function chunkProducers(ChunkBuffer $buffer, int $size, ?int $timeout = null): array
{
    $producers = [];

    foreach (range(0, 7) as $producer) {
        $producers[] = function () use ($buffer, $producer, $size, $timeout): void {
            foreach (range(1, 25) as $item) {
                $buffer->push(
                    'imports',
                    ChunkRecorderJob::class,
                    ['id' => $producer * 100 + $item],
                    size: $size,
                    timeout: $timeout,
                );
            }
        };
    }

    return $producers;
}

/**
 * @return list<int>
 */
function producedChunkIds(): array
{
    $ids = [];

    foreach (range(0, 7) as $producer) {
        foreach (range(1, 25) as $item) {
            $ids[] = $producer * 100 + $item;
        }
    }

    return $ids;
}

/**
 * @return list<mixed>
 */
function recordedChunkIds(): array
{
    $ids = array_column(array_merge(...ChunkRecorderJob::$chunks), 'id');
    sort($ids);

    return $ids;
}

beforeEach(function (): void {
    ChunkRecorderJob::$chunks = [];
});

it('loses no items when many producers fill chunks concurrently', function (): void {
    InterleavingArrayStore::register('interleaving');
    config()->set('zenith.chunks.store', 'interleaving');

    $lockWaits = InterleavingArrayStore::interleave(chunkProducers(app(ChunkBuffer::class), size: 10));

    expect(recordedChunkIds())->toBe(producedChunkIds())
        ->and(ChunkRecorderJob::$chunks)->toHaveCount(20)
        ->and(array_unique(array_map(count(...), ChunkRecorderJob::$chunks)))->toBe([10])
        ->and($lockWaits)->toBeGreaterThan(0);
});

it('loses no items when many producers buffer concurrently until a timeout flush', function (): void {
    InterleavingArrayStore::register('interleaving');
    config()->set('zenith.chunks.store', 'interleaving');

    $buffer = app(ChunkBuffer::class);
    $lockWaits = InterleavingArrayStore::interleave(chunkProducers($buffer, size: 1000, timeout: 60));

    expect(ChunkRecorderJob::$chunks)->toBe([]);

    $this->travel(61)->seconds();

    expect($buffer->flushDue())->toBe(1)
        ->and(recordedChunkIds())->toBe(producedChunkIds())
        ->and(ChunkRecorderJob::$chunks)->toHaveCount(1)
        ->and($lockWaits)->toBeGreaterThan(0);
});

it('flushes only the buffers whose timeout has elapsed', function (): void {
    $buffer = app(ChunkBuffer::class);

    $buffer->push('early', ChunkRecorderJob::class, ['id' => 1], size: 10, timeout: 30);
    $buffer->push('late', ChunkRecorderJob::class, ['id' => 2], size: 10, timeout: 120);
    $buffer->push('untimed', ChunkRecorderJob::class, ['id' => 3], size: 10);

    expect($buffer->flushDue())->toBe(0);

    $this->travel(31)->seconds();

    expect($buffer->flushDue())->toBe(1)
        ->and(ChunkRecorderJob::$chunks)->toBe([[['id' => 1]]]);

    $this->travel(90)->seconds();

    expect($buffer->flushDue())->toBe(1)
        ->and($buffer->flushDue())->toBe(0)
        ->and(ChunkRecorderJob::$chunks)->toBe([[['id' => 1]], [['id' => 2]]]);
});

it('counts the timeout from the first item in the buffer', function (): void {
    $buffer = app(ChunkBuffer::class);

    $buffer->push('window', ChunkRecorderJob::class, ['id' => 1], size: 10, timeout: 60);

    $this->travel(40)->seconds();
    $buffer->push('window', ChunkRecorderJob::class, ['id' => 2], size: 10, timeout: 60);

    $this->travel(21)->seconds();

    expect($buffer->flushDue())->toBe(1)
        ->and(ChunkRecorderJob::$chunks)->toBe([[['id' => 1], ['id' => 2]]]);
});

it('starts a new timeout after a full chunk is flushed', function (): void {
    $buffer = app(ChunkBuffer::class);

    $buffer->push('batch', ChunkRecorderJob::class, ['id' => 1], size: 2, timeout: 60);
    $buffer->push('batch', ChunkRecorderJob::class, ['id' => 2], size: 2, timeout: 60);

    $this->travel(30)->seconds();
    $buffer->push('batch', ChunkRecorderJob::class, ['id' => 3], size: 2, timeout: 60);

    $this->travel(31)->seconds();

    expect($buffer->flushDue())->toBe(0)
        ->and(ChunkRecorderJob::$chunks)->toBe([[['id' => 1], ['id' => 2]]]);

    $this->travel(30)->seconds();

    expect($buffer->flushDue())->toBe(1)
        ->and(ChunkRecorderJob::$chunks)->toBe([[['id' => 1], ['id' => 2]], [['id' => 3]]]);
});

it('flushes a due buffer together with the next pushed item', function (): void {
    $buffer = app(ChunkBuffer::class);

    $buffer->push('overdue', ChunkRecorderJob::class, ['id' => 1], size: 10, timeout: 60);

    $this->travel(61)->seconds();
    $buffer->push('overdue', ChunkRecorderJob::class, ['id' => 2], size: 10, timeout: 60);

    expect(ChunkRecorderJob::$chunks)->toBe([[['id' => 1], ['id' => 2]]])
        ->and($buffer->flushDue())->toBe(0);
});

it('keeps buffers in the configured cache store', function (): void {
    config()->set('cache.stores.chunks', ['driver' => 'array']);
    config()->set('zenith.chunks.store', 'chunks');

    $buffer = app(ChunkBuffer::class);
    $buffer->push('routed', ChunkRecorderJob::class, ['id' => 1], size: 10, timeout: 60);

    $this->travel(61)->seconds();
    config()->set('zenith.chunks.store', null);

    expect($buffer->flushDue())->toBe(0);

    config()->set('zenith.chunks.store', 'chunks');

    expect($buffer->flushDue())->toBe(1)
        ->and(ChunkRecorderJob::$chunks)->toBe([[['id' => 1]]]);
});
