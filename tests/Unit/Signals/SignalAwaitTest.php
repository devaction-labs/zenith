<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Signals\ReleaseWhileWaiting;
use DevactionLabs\Zenith\Signals\Signal;
use DevactionLabs\Zenith\Signals\SignalTimeoutException;
use DevactionLabs\Zenith\Signals\SignalWaiting;
use DevactionLabs\Zenith\Tests\Unit\Signals\InterleavingArrayStore;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

final class SignalGatedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @var list<array<string, mixed>> */
    public static array $received = [];

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ReleaseWhileWaiting];
    }

    public function handle(): void
    {
        self::$received[] = Signal::await('gate', seconds: 300, retryAfter: 1);
    }
}

function signalQueueJob(): SyncJob
{
    return new SyncJob(app(), '{}', 'sync', 'default');
}

beforeEach(function (): void {
    SignalGatedJob::$received = [];
});

it('keeps waiting until the deadline measured from the first wait', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('release')->twice()->with(5);
    app()->instance(Job::class, $job);

    expect(fn () => Signal::await('approval', seconds: 30, retryAfter: 5))
        ->toThrow(SignalWaiting::class);

    $this->travel(20)->seconds();

    expect(fn () => Signal::await('approval', seconds: 30, retryAfter: 5))
        ->toThrow(SignalWaiting::class);

    $this->travel(11)->seconds();

    expect(fn () => Signal::await('approval', seconds: 30, retryAfter: 5))
        ->toThrow(SignalTimeoutException::class, 'Signal [approval] did not arrive within 30 seconds.');
});

it('starts a new deadline after a wait times out', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('release')->twice()->with(5);
    app()->instance(Job::class, $job);

    expect(fn () => Signal::await('retry', seconds: 10, retryAfter: 5))
        ->toThrow(SignalWaiting::class);

    $this->travel(11)->seconds();

    expect(fn () => Signal::await('retry', seconds: 10, retryAfter: 5))
        ->toThrow(SignalTimeoutException::class)
        ->and(fn () => Signal::await('retry', seconds: 10, retryAfter: 5))
        ->toThrow(SignalWaiting::class);
});

it('forgets the deadline once the signal arrives', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('release')->twice()->with(5);
    app()->instance(Job::class, $job);

    expect(fn () => Signal::await('handoff', seconds: 10, retryAfter: 5))
        ->toThrow(SignalWaiting::class);

    Signal::send('handoff', ['ok' => true]);

    expect(Signal::await('handoff', seconds: 10, retryAfter: 5))->toBe(['ok' => true]);

    $this->travel(11)->seconds();

    expect(fn () => Signal::await('handoff', seconds: 10, retryAfter: 5))
        ->toThrow(SignalWaiting::class);
});

it('times out without releasing the job when there is no retry delay', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldNotReceive('release');
    app()->instance(Job::class, $job);

    expect(fn () => Signal::await('immediate', seconds: 30))
        ->toThrow(SignalTimeoutException::class);
});

it('times out when awaited outside a queued job', function (): void {
    expect(fn () => Signal::await('outside', seconds: 30, retryAfter: 5))
        ->toThrow(SignalTimeoutException::class);
});

it('keeps signals for the configured time to live', function (): void {
    config()->set('zenith.signals.ttl', 120);

    Signal::send('kept', ['id' => 1]);
    Signal::send('expired', ['id' => 2]);

    $this->travel(119)->seconds();

    expect(Signal::pull('kept'))->toBe(['id' => 1]);

    $this->travel(2)->seconds();

    expect(Signal::pull('expired'))->toBeNull();
});

it('stores signals in the configured cache store', function (): void {
    config()->set('cache.stores.signals', ['driver' => 'array']);
    config()->set('zenith.signals.store', 'signals');

    Signal::send('routed', ['ok' => true]);

    config()->set('zenith.signals.store', null);

    expect(Signal::pull('routed'))->toBeNull();

    config()->set('zenith.signals.store', 'signals');

    expect(Signal::pull('routed'))->toBe(['ok' => true]);
});

it('delivers a signal to exactly one of several concurrent consumers', function (): void {
    InterleavingArrayStore::register('interleaving');
    config()->set('zenith.signals.store', 'interleaving');

    Signal::send('once', ['ok' => true]);

    $received = [];
    $consume = function () use (&$received): void {
        $received[] = Signal::pull('once');
    };

    $lockWaits = InterleavingArrayStore::interleave([$consume, $consume, $consume, $consume]);

    expect($received)->toHaveCount(4)
        ->and(array_values(array_filter($received)))->toBe([['ok' => true]])
        ->and($lockWaits)->toBeGreaterThan(0);
});

it('keeps a job that waits through many releases from exhausting its attempts', function (): void {
    config()->set('database.connections.signal_queue', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('queue.connections.database.connection', 'signal_queue');

    Schema::connection('signal_queue')->create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedSmallInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    $failures = [];
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failures): void {
        $failures[] = $event->exception->getMessage();
    });

    dispatch((new SignalGatedJob)->onConnection('database'));

    $worker = new Worker(
        app(QueueManager::class),
        app(Dispatcher::class),
        app(ExceptionHandler::class),
        static fn (): bool => false,
    );
    $options = new WorkerOptions(sleep: 0, maxTries: 1);

    foreach (range(1, 5) as $delivery) {
        $worker->runNextJob('database', 'default', $options);
        $this->travel(2)->seconds();
    }

    $jobs = DB::connection('signal_queue')->table('jobs');

    expect($failures)->toBe([])
        ->and($jobs->clone()->count())->toBe(1)
        ->and($jobs->clone()->value('attempts'))->toBe(5)
        ->and(SignalGatedJob::$received)->toBe([]);

    Signal::send('gate', ['approved' => true]);
    $worker->runNextJob('database', 'default', $options);

    expect($failures)->toBe([])
        ->and($jobs->clone()->count())->toBe(0)
        ->and(SignalGatedJob::$received)->toBe([['approved' => true]]);
});

it('exposes the running queue job only while the middleware handles it', function (): void {
    $job = signalQueueJob();
    $exposed = null;

    (new ReleaseWhileWaiting)->handle((new SignalGatedJob)->setJob($job), function () use (&$exposed): void {
        $exposed = app(Job::class);
    });

    expect($exposed)->toBe($job)
        ->and(app()->bound(Job::class))->toBeFalse();
});

it('restores the queue job that was exposed before the middleware ran', function (): void {
    $outer = Mockery::mock(Job::class);
    app()->instance(Job::class, $outer);

    (new ReleaseWhileWaiting)->handle(
        (new SignalGatedJob)->setJob(signalQueueJob()),
        static fn (): null => null,
    );

    expect(app(Job::class))->toBe($outer);
});

it('treats a signal wait as a completed release', function (): void {
    $result = (new ReleaseWhileWaiting)->handle(
        (new SignalGatedJob)->setJob(signalQueueJob()),
        static fn (): never => throw new SignalWaiting('Waiting for signal [gate].'),
    );

    expect($result)->toBeNull()
        ->and(app()->bound(Job::class))->toBeFalse();
});

it('rethrows job failures that are not waits', function (): void {
    expect(fn () => (new ReleaseWhileWaiting)->handle(
        (new SignalGatedJob)->setJob(signalQueueJob()),
        static fn (): never => throw new RuntimeException('boom'),
    ))->toThrow(RuntimeException::class, 'boom')
        ->and(app()->bound(Job::class))->toBeFalse();
});
