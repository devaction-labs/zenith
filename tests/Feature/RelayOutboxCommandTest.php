<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Outbox\Outbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

final class RelayOutboxCommandProbeJob implements ShouldQueue
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
    RelayOutboxCommandProbeJob::$handled = [];
    Date::setTestNow('2026-07-20 12:00:00 UTC');
});

afterEach(function (): void {
    Date::setTestNow();
});

it('relays due outbox rows using the default grace period', function (): void {
    DB::transaction(function (): void {
        Outbox::dispatch(new RelayOutboxCommandProbeJob('due'));
    });

    Date::setTestNow(Date::now()->addSeconds(30));

    $command = artisan('zenith:relay-outbox');

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('The relay outbox command did not return a pending command.');
    }

    $command
        ->expectsOutputToContain('Relayed 1 outbox row(s).')
        ->assertSuccessful()
        ->execute();

    expect(RelayOutboxCommandProbeJob::$handled)->toBe(['due'])
        ->and(DB::table('zenith_outbox')->count())->toBe(0);
});

it('honors a custom grace period', function (): void {
    DB::transaction(function (): void {
        Outbox::dispatch(new RelayOutboxCommandProbeJob('too-fresh'));
    });

    $command = artisan('zenith:relay-outbox', ['--grace-seconds' => 60]);

    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('The relay outbox command did not return a pending command.');
    }

    $command
        ->expectsOutputToContain('Relayed 0 outbox row(s).')
        ->assertSuccessful()
        ->execute();

    expect(RelayOutboxCommandProbeJob::$handled)->toBe([])
        ->and(DB::table('zenith_outbox')->count())->toBe(1);
});
