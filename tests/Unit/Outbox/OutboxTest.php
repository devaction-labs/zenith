<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Outbox\Outbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class OutboxProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    public static array $handled = [];

    public function __construct(public string $marker) {}

    public function handle(): void
    {
        self::$handled[] = $this->marker;
    }
}

beforeEach(function (): void {
    migrateOutboxTable();
    OutboxProbeJob::$handled = [];
    Date::setTestNow('2026-07-20 12:00:00 UTC');
});

afterEach(function (): void {
    Date::setTestNow();
});

it('writes a row inside the caller transaction without dispatching immediately', function (): void {
    Bus::fake();

    DB::transaction(function (): void {
        Outbox::dispatch(new OutboxProbeJob('committed-with-data'));
    });

    expect(DB::table('zenith_outbox')->count())->toBe(1)
        ->and(DB::table('zenith_outbox')->whereNull('sent_at')->count())->toBe(1);

    Bus::assertNothingDispatched();
});

it('rolls back the outbox row with the transaction that wrote it', function (): void {
    try {
        DB::transaction(function (): void {
            Outbox::dispatch(new OutboxProbeJob('never-committed'));

            throw new RuntimeException('simulated failure before commit');
        });
    } catch (RuntimeException) {
        // The application data this job depends on also rolled back.
    }

    expect(DB::table('zenith_outbox')->count())->toBe(0);
});

it('relays and removes due rows, dispatching the original job', function (): void {
    DB::transaction(function (): void {
        Outbox::dispatch(new OutboxProbeJob('relay-me'));
    });

    $relayed = Outbox::relayDue();

    expect($relayed)->toBe(1)
        ->and(OutboxProbeJob::$handled)->toBe(['relay-me'])
        ->and(DB::table('zenith_outbox')->count())->toBe(0);
});

it('recovers a row whose relay never ran, simulating a crash between commit and push', function (): void {
    DB::transaction(function (): void {
        Outbox::dispatch(new OutboxProbeJob('crashed-before-push'));
    });

    expect(OutboxProbeJob::$handled)->toBe([])
        ->and(DB::table('zenith_outbox')->whereNull('sent_at')->count())->toBe(1);

    Date::setTestNow(Date::now()->addSeconds(45));

    $relayed = Outbox::relayDue(graceSeconds: 30);

    expect($relayed)->toBe(1)
        ->and(OutboxProbeJob::$handled)->toBe(['crashed-before-push']);
});

it('skips rows that have not reached the grace period yet', function (): void {
    DB::transaction(function (): void {
        Outbox::dispatch(new OutboxProbeJob('too-fresh'));
    });

    expect(Outbox::relayDue(graceSeconds: 30))->toBe(0)
        ->and(OutboxProbeJob::$handled)->toBe([]);
});

it('applies the connection and queue overrides when relaying', function (): void {
    Bus::fake();

    DB::transaction(function (): void {
        Outbox::dispatch(new OutboxProbeJob('routed'), connection: 'redis', queue: 'imports');
    });

    Outbox::relayDue();

    Bus::assertDispatched(
        OutboxProbeJob::class,
        fn (OutboxProbeJob $job): bool => $job->connection === 'redis' && $job->queue === 'imports',
    );
});

it('reports the backlog count and the oldest pending row age', function (): void {
    DB::transaction(function (): void {
        Outbox::dispatch(new OutboxProbeJob('oldest'));
    });

    Date::setTestNow(Date::now()->addSeconds(10));

    DB::transaction(function (): void {
        Outbox::dispatch(new OutboxProbeJob('newest'));
    });

    $backlog = Outbox::backlog();

    expect($backlog['count'])->toBe(2)
        ->and($backlog['oldestPendingSeconds'])->toBe(10);
});

it('reports an empty backlog when there is nothing pending', function (): void {
    expect(Outbox::backlog())->toBe(['count' => 0, 'oldestPendingSeconds' => null]);
});
