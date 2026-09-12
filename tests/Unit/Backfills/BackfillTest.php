<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Backfills\Backfill;
use DevactionLabs\Zenith\Backfills\RunBackfillPage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

final class FlakyBackfillPage
{
    /** @var list<int> */
    public static array $seen = [];

    public static bool $failOnce = false;

    /**
     * @return array{cursor: int, done: bool}
     */
    public function __invoke(?int $cursor): array
    {
        $cursor ??= 0;
        self::$seen[] = $cursor;

        if ($cursor === 1 && self::$failOnce) {
            self::$failOnce = false;

            throw new RuntimeException('Page 1 failed.');
        }

        return ['cursor' => $cursor + 1, 'done' => $cursor >= 2];
    }
}

final class StuckBackfillPage
{
    public static int $calls = 0;

    /**
     * @return array{cursor: int, done: bool}
     */
    public function __invoke(?int $cursor): array
    {
        self::$calls++;

        return ['cursor' => $cursor ?? 0, 'done' => false];
    }
}

function handleBackfillPage(mixed $job): void
{
    if (! $job instanceof RunBackfillPage) {
        throw new UnexpectedValueException('Expected a queued backfill page.');
    }

    $job->handle(app(Container::class), app(Dispatcher::class));
}

beforeEach(function (): void {
    FlakyBackfillPage::$seen = [];
    FlakyBackfillPage::$failOnce = false;
    StuckBackfillPage::$calls = 0;
});

it('queues exactly one page job for a class-based backfill', function (): void {
    Bus::fake([RunBackfillPage::class]);

    Backfill::make(FlakyBackfillPage::class)->dispatch(5);

    Bus::assertDispatchedTimes(RunBackfillPage::class, 1);
    Bus::assertDispatched(
        RunBackfillPage::class,
        fn (RunBackfillPage $job): bool => $job->page === FlakyBackfillPage::class && $job->cursor === 5,
    );
    expect(FlakyBackfillPage::$seen)->toBe([]);
});

it('retries a failed page without re-running earlier pages', function (): void {
    FlakyBackfillPage::$failOnce = true;
    Bus::fake([RunBackfillPage::class]);

    Backfill::make(FlakyBackfillPage::class)->dispatch();
    handleBackfillPage(Bus::dispatched(RunBackfillPage::class)->sole());

    $failedPage = Bus::dispatched(
        RunBackfillPage::class,
        fn (RunBackfillPage $job): bool => $job->cursor === 1,
    )->sole();

    expect(fn () => handleBackfillPage($failedPage))->toThrow(RuntimeException::class, 'Page 1 failed.');

    handleBackfillPage($failedPage);

    expect(FlakyBackfillPage::$seen)->toBe([0, 1, 1])
        ->and(Bus::dispatched(RunBackfillPage::class, fn (RunBackfillPage $job): bool => $job->cursor === 2))
        ->toHaveCount(1);
});

it('queues continuation pages on the connection and queue of the current page', function (): void {
    Bus::fake([RunBackfillPage::class]);

    handleBackfillPage((new RunBackfillPage(FlakyBackfillPage::class))->onConnection('redis')->onQueue('backfills'));

    Bus::assertDispatched(
        RunBackfillPage::class,
        fn (RunBackfillPage $job): bool => $job->cursor === 1
            && $job->connection === 'redis'
            && $job->queue === 'backfills',
    );
});

it('stops queueing pages when a page does not advance the cursor', function (): void {
    Backfill::make(StuckBackfillPage::class)->dispatch(7);

    expect(StuckBackfillPage::$calls)->toBe(1);
});

it('reports the completed page count when the last page finishes', function (): void {
    Log::shouldReceive('info')
        ->once()
        ->with('Zenith backfill completed.', [
            'page' => FlakyBackfillPage::class,
            'pages' => 3,
            'cursor' => 3,
        ]);

    Backfill::make(FlakyBackfillPage::class)->dispatch();

    expect(FlakyBackfillPage::$seen)->toBe([0, 1, 2]);
});

it('rejects a page class that is not invokable', function (): void {
    expect(fn (): Backfill => Backfill::make(stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});
