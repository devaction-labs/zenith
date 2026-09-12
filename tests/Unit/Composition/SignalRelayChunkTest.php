<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Chunks\ChunkBuffer;
use DevactionLabs\Zenith\Relay\Relay;
use DevactionLabs\Zenith\Relay\RelayWaiting;
use DevactionLabs\Zenith\Signals\Signal;
use DevactionLabs\Zenith\Signals\SignalTimeoutException;
use DevactionLabs\Zenith\Signals\SignalWaiting;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RelayProbeJob implements ShouldQueue
{
    use Queueable;

    /**
     * @return array{ok: true, n: int}
     */
    public function handle(): array
    {
        return ['ok' => true, 'n' => 7];
    }
}

final class ChunkProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var list<array<string, mixed>> */
    public static array $received = [];

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function handle(array $items): void
    {
        self::$received = $items;
    }
}

beforeEach(function (): void {
    ChunkProbeJob::$received = [];
    app(Repository::class)->clear();
});

it('stores and consumes a named signal', function (): void {
    Signal::send('approval', ['decision' => 'approved']);

    expect(Signal::pull('approval'))->toBe(['decision' => 'approved'])
        ->and(Signal::pull('approval'))->toBeNull();
});

it('times out when a signal never arrives', function (): void {
    expect(fn () => Signal::await('missing', 0))
        ->toThrow(SignalTimeoutException::class);
});

it('awaits a relayed job result', function (): void {
    $pending = Relay::async(new RelayProbeJob);

    expect(Relay::await($pending))->toBe(['ok' => true, 'n' => 7]);
});

it('flushes a chunk when the size is reached', function (): void {
    $buffer = app(ChunkBuffer::class);

    $buffer->push('imports', ChunkProbeJob::class, ['id' => 1], size: 2);
    expect(ChunkProbeJob::$received)->toBe([]);

    $buffer->push('imports', ChunkProbeJob::class, ['id' => 2], size: 2);

    expect(ChunkProbeJob::$received)->toBe([
        ['id' => 1],
        ['id' => 2],
    ]);
});

it('releases the current queue job while waiting for a signal', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('release')->once()->with(5);
    app()->instance(Job::class, $job);

    expect(fn () => Signal::await('later', seconds: 30, retryAfter: 5))
        ->toThrow(SignalWaiting::class);

    Signal::send('later', ['ok' => true]);

    expect(Signal::await('later', seconds: 30))->toBe(['ok' => true]);
});

it('releases the current queue job while a relay is unfinished', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('release')->once()->with(5);
    app()->instance(Job::class, $job);

    expect(fn () => Relay::await('missing-relay', seconds: 30, retryAfter: 5))
        ->toThrow(RelayWaiting::class);
});

it('flushes a chunk when its timeout elapses', function (): void {
    $buffer = app(ChunkBuffer::class);

    $buffer->push('late', ChunkProbeJob::class, ['id' => 9], size: 10, timeout: 60);
    expect(ChunkProbeJob::$received)->toBe([]);

    $this->travel(61)->seconds();
    $buffer->flushDue();

    expect(ChunkProbeJob::$received)->toBe([['id' => 9]]);
});
