<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Chains\Attributes\ChainBy;
use DevactionLabs\Zenith\Chains\EnforceChainOrder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Schema;

#[ChainBy('demo-chain')]
final class ChainedDemoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<string> */
    public static array $executed = [];

    public function __construct(public string $label) {}

    /** @return list<EnforceChainOrder> */
    public function middleware(): array
    {
        return [new EnforceChainOrder];
    }

    public function handle(): void
    {
        self::$executed[] = $this->label;
    }
}

#[ChainBy('other-demo-chain')]
final class OtherChainedDemoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<string> */
    public static array $executed = [];

    public function __construct(public string $label) {}

    /** @return list<EnforceChainOrder> */
    public function middleware(): array
    {
        return [new EnforceChainOrder];
    }

    public function handle(): void
    {
        self::$executed[] = $this->label;
    }
}

function chainOrderingWorker(): Worker
{
    return new Worker(
        app(QueueManager::class),
        app(Dispatcher::class),
        app(ExceptionHandler::class),
        static fn (): bool => false,
    );
}

beforeEach(function (): void {
    ChainedDemoJob::$executed = [];
    OtherChainedDemoJob::$executed = [];

    config()->set('database.connections.chain_queue', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('queue.connections.database.connection', 'chain_queue');

    Schema::connection('chain_queue')->create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedSmallInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

it('runs jobs sharing a key in strict FIFO dispatch order even when an earlier job becomes available later', function (): void {
    dispatch((new ChainedDemoJob('a'))->onConnection('database')->delay(30));
    dispatch((new ChainedDemoJob('b'))->onConnection('database'));
    dispatch((new ChainedDemoJob('c'))->onConnection('database'));

    $worker = chainOrderingWorker();
    $options = new WorkerOptions(sleep: 0, maxTries: 5);

    foreach (range(1, 8) as $attempt) {
        $worker->runNextJob('database', 'default', $options);
        $this->travel(31)->seconds();
    }

    expect(ChainedDemoJob::$executed)->toBe(['a', 'b', 'c']);
});

it('runs jobs in different chains independently, without one chain blocking the other', function (): void {
    dispatch((new ChainedDemoJob('a1'))->onConnection('database')->delay(30));
    dispatch((new ChainedDemoJob('a2'))->onConnection('database'));
    dispatch((new OtherChainedDemoJob('x1'))->onConnection('database'));
    dispatch((new OtherChainedDemoJob('x2'))->onConnection('database'));

    $worker = chainOrderingWorker();
    $options = new WorkerOptions(sleep: 0, maxTries: 5);

    foreach (range(1, 3) as $attempt) {
        $worker->runNextJob('database', 'default', $options);
    }

    expect(OtherChainedDemoJob::$executed)->toBe(['x1', 'x2'])
        ->and(ChainedDemoJob::$executed)->toBe([]);
});
