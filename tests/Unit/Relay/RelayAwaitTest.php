<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Relay\Relay;
use DevactionLabs\Zenith\Relay\RelayFailedException;
use DevactionLabs\Zenith\Relay\RelayTimeoutException;
use DevactionLabs\Zenith\Relay\RelayWaiting;
use DevactionLabs\Zenith\Signals\ReleaseWhileWaiting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

final class RelayDeclinedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): never
    {
        throw new DomainException('Payment declined.');
    }
}

it('waits outside a job until the relayed result arrives', function (): void {
    Sleep::fake(syncWithCarbon: true);

    $naps = 0;
    Sleep::whenFakingSleep(function () use (&$naps): void {
        $naps++;

        if ($naps === 3) {
            Relay::record('late-result', ['quote' => 42]);
        }
    });

    expect(Relay::await('late-result', seconds: 30))->toBe(['quote' => 42])
        ->and($naps)->toBe(3);
});

it('times out outside a job once the requested seconds have passed', function (): void {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);

    $naps = 0;
    Sleep::whenFakingSleep(function () use (&$naps): void {
        $naps++;
    });

    $startedAt = Date::now();

    expect(fn () => Relay::await('never', seconds: 3))
        ->toThrow(RelayTimeoutException::class, 'Relay [never] did not finish within 3 seconds.')
        ->and($startedAt->diffInMilliseconds(Date::now()))->toBeGreaterThanOrEqual(3000.0)->toBeLessThan(3100.0)
        ->and($naps)->toBeGreaterThan(1)->toBeLessThan(25);
});

it('propagates the failure class and message of the relayed job', function (): void {
    $id = Str::freezeUuids(
        fn () => expect(fn () => Relay::async(new RelayDeclinedJob))
            ->toThrow(DomainException::class, 'Payment declined.'),
    )->toString();

    $failure = null;

    try {
        Relay::await($id);
    } catch (RelayFailedException $exception) {
        $failure = $exception;
    }

    expect($failure?->exceptionClass)->toBe(DomainException::class)
        ->and($failure?->getMessage())->toBe('Payment declined.')
        ->and($failure?->relayId)->toBe($id);
});

it('keeps relayed results for the configured time to live', function (): void {
    config()->set('zenith.relay.ttl', 60);

    Relay::record('short-lived', 'done');

    $this->travel(59)->seconds();

    expect(Relay::await('short-lived', seconds: 0))->toBe('done');

    $this->travel(2)->seconds();

    expect(fn () => Relay::await('short-lived', seconds: 0))
        ->toThrow(RelayTimeoutException::class);
});

it('stores relayed results in the configured cache store', function (): void {
    config()->set('cache.stores.relays', ['driver' => 'array']);
    config()->set('zenith.relay.store', 'relays');

    Relay::record('routed', 'done');

    config()->set('zenith.relay.store', null);

    expect(fn () => Relay::await('routed', seconds: 0))->toThrow(RelayTimeoutException::class);

    config()->set('zenith.relay.store', 'relays');

    expect(Relay::await('routed', seconds: 0))->toBe('done');
});

it('releases the job until the deadline measured from the first wait passes', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('release')->twice()->with(5);
    app()->instance(Job::class, $job);

    expect(fn () => Relay::await('slow', seconds: 30, retryAfter: 5))
        ->toThrow(RelayWaiting::class);

    $this->travel(20)->seconds();

    expect(fn () => Relay::await('slow', seconds: 30, retryAfter: 5))
        ->toThrow(RelayWaiting::class);

    $this->travel(11)->seconds();

    expect(fn () => Relay::await('slow', seconds: 30, retryAfter: 5))
        ->toThrow(RelayTimeoutException::class, 'Relay [slow] did not finish within 30 seconds.');
});

it('returns a result recorded while the job was released', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('release')->once()->with(5);
    app()->instance(Job::class, $job);

    expect(fn () => Relay::await('handoff', seconds: 30, retryAfter: 5))
        ->toThrow(RelayWaiting::class);

    Relay::record('handoff', ['ok' => true]);

    expect(Relay::await('handoff', seconds: 30, retryAfter: 5))->toBe(['ok' => true]);
});

it('waits in place inside a job when there is no retry delay', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance(Job::class, $job);

    Sleep::fake(syncWithCarbon: true);

    $naps = 0;
    Sleep::whenFakingSleep(function () use (&$naps): void {
        $naps++;
    });

    expect(fn () => Relay::await('in-place', seconds: 2))
        ->toThrow(RelayTimeoutException::class)
        ->and($naps)->toBeGreaterThan(0);
});

it('treats a relay wait as a completed release', function (): void {
    $result = (new ReleaseWhileWaiting)->handle(
        (new RelayDeclinedJob)->setJob(new SyncJob(app(), '{}', 'sync', 'default')),
        static fn (): never => throw new RelayWaiting('Waiting for relay [quote].'),
    );

    expect($result)->toBeNull();
});
