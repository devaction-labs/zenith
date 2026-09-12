<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Batches\BatchRepositoryOverview;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\horizonBatch;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

afterEach(function (): void {
    app(CacheFactory::class)->store()->clear();
});

it('shares one repository scan for batch counts and previews during a poll interval', function (): void {
    config()->set('zenith.poll_interval', 5000);
    app(CacheFactory::class)->store()->clear();

    $active = horizonBatch(
        'batch-2',
        name: 'Active import',
        totalJobs: 4,
        pendingJobs: 2,
    );
    $failedOnly = horizonBatch(
        'batch-1',
        totalJobs: 2,
        pendingJobs: 1,
        failedJobs: 1,
    );
    $repository = mockDashboardContract(BatchRepository::class);
    $repositoryCalls = 0;
    dashboardReturnsUsing(
        $repository,
        'get',
        static function (int $limit, ?string $before) use ($active, $failedOnly, &$repositoryCalls): array {
            $repositoryCalls++;

            return match ($before) {
                null => [$active, $failedOnly],
                'batch-1' => [],
                default => throw new LogicException("Unexpected batch cursor [{$before}]."),
            };
        },
    );

    $first = (new BatchRepositoryOverview(
        $repository,
        app(CacheFactory::class),
    ))->get();
    $second = (new BatchRepositoryOverview(
        $repository,
        app(CacheFactory::class),
    ))->get();

    expect($first)->toBe([
        'total' => 2,
        'active' => 1,
        'complete' => true,
        'message' => null,
        'previews' => [
            ['id' => 'batch-2', 'name' => 'Active import', 'progress' => 50],
        ],
    ])->and($second)->toBe($first)
        ->and($repositoryCalls)->toBe(2);
});

it('uses the configured or default poll interval for its cache ttl', function (
    ?int $pollInterval,
    int $expectedCacheSeconds,
): void {
    config()->set(
        'zenith',
        $pollInterval === null ? [] : ['poll_interval' => $pollInterval],
    );

    $repository = mockDashboardContract(BatchRepository::class);
    $cache = mockDashboardContract(CacheRepository::class);
    $factory = mockDashboardContract(CacheFactory::class);
    $active = horizonBatch(
        'batch-2',
        name: 'Active import',
        totalJobs: 4,
        pendingJobs: 2,
    );

    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [$active],
            'batch-2' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    dashboardExpects($factory, 'store', times: 'once', value: $cache);
    dashboardExpects(
        $cache,
        'remember',
        [
            'zenith:batch-repository-overview:v1',
            $expectedCacheSeconds,
            Mockery::type(Closure::class),
        ],
        'once',
        returnUsing: static fn (string $key, int $seconds, Closure $callback): array => $callback(),
    );

    $overview = new BatchRepositoryOverview($repository, $factory);

    expect($overview->get())->toBe([
        'total' => 1,
        'active' => 1,
        'complete' => true,
        'message' => null,
        'previews' => [
            ['id' => 'batch-2', 'name' => 'Active import', 'progress' => 50],
        ],
    ]);
})->with([
    'configured 1500ms interval' => [1500, 1],
    'cached config without the interval' => [null, 5],
]);

it('bypasses batch repository overview caching for subsecond poll intervals', function (): void {
    config()->set('zenith.poll_interval', 999);

    $active = horizonBatch(
        'batch-2',
        name: 'Active import',
        totalJobs: 4,
        pendingJobs: 2,
    );
    $repository = mockDashboardContract(BatchRepository::class);
    $repositoryCalls = 0;
    dashboardReturnsUsing(
        $repository,
        'get',
        static function (int $limit, ?string $before) use ($active, &$repositoryCalls): array {
            $repositoryCalls++;

            return match ($before) {
                null => [$active],
                'batch-2' => [],
                default => throw new LogicException("Unexpected batch cursor [{$before}]."),
            };
        },
    );

    $factory = mockDashboardContract(CacheFactory::class);
    dashboardExpects($factory, 'store', times: 'never');

    $overview = new BatchRepositoryOverview($repository, $factory);

    expect($overview->get()['total'])->toBe(1)
        ->and($overview->get()['total'])->toBe(1)
        ->and($repositoryCalls)->toBe(4);
});

it('counts every retained batch across repository pages', function (): void {
    config()->set('zenith.poll_interval', 0);
    // Former public default was 1000; page size is 100, so this forces 11 repository pages.
    config()->set('zenith.retained_batch_scan_limit', 1000);

    $calls = 0;
    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static function (int $limit, ?string $before) use (&$calls): array {
            $calls++;

            expect($limit)->toBe(100);

            $start = match ($before) {
                null => 1001,
                default => ((int) str_replace('batch-', '', (string) $before)) - 1,
            };

            if ($start < 1) {
                return [];
            }

            $end = max(1, $start - $limit + 1);

            return array_map(
                static function (int $index) {
                    $batch = horizonBatch(
                        sprintf('batch-%04d', $index),
                        name: $index === 1001 ? 'Active import' : ($index === 1 ? 'Oldest active' : ''),
                        totalJobs: 4,
                        pendingJobs: in_array($index, [1001, 1], true) ? ($index === 1001 ? 1 : 2) : 0,
                        finishedAt: in_array($index, [1001, 1], true) ? null : 1_784_281_000 + $index,
                    );

                    return $batch;
                },
                range($start, $end),
            );
        },
    );

    $overview = (new BatchRepositoryOverview($repository, app(CacheFactory::class)))->get();

    expect($overview['total'])->toBe(1001)
        ->and($overview['active'])->toBe(2)
        ->and($overview['complete'])->toBeTrue()
        ->and($overview['message'])->toBeNull()
        ->and($overview['previews'])->toBe([
            ['id' => 'batch-1001', 'name' => 'Active import', 'progress' => 75],
            ['id' => 'batch-0001', 'name' => 'Oldest active', 'progress' => 50],
        ])
        // 10 full pages of 100 + 1 trailing page + 1 empty terminator.
        ->and($calls)->toBe(12);
});

it('fails closed when the batch repository does not advance its cursor', function (): void {
    config()->set('zenith.poll_interval', 0);

    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [horizonBatch('batch-2', name: 'Active import', pendingJobs: 1)],
            'batch-2' => [horizonBatch('batch-2', name: 'Active import', pendingJobs: 1)],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    expect(fn () => (new BatchRepositoryOverview($repository, app(CacheFactory::class)))->get())
        ->toThrow(RuntimeException::class, 'The batch repository did not advance its pagination cursor.');
});

it('previews only the best three active batches by progress descending with id tie-break', function (): void {
    config()->set('zenith.poll_interval', 0);

    $newestZero = horizonBatch(
        'preview-new-zero',
        name: 'Newest zero progress',
        totalJobs: 100,
        pendingJobs: 100,
    );
    $ten = horizonBatch(
        'preview-ten',
        name: 'Ten percent',
        totalJobs: 100,
        pendingJobs: 90,
    );
    $midZ = horizonBatch(
        'preview-mid-z',
        name: 'Thirty-five percent z',
        totalJobs: 100,
        pendingJobs: 65,
    );
    $midA = horizonBatch(
        'preview-mid-a',
        name: 'Thirty-five percent a',
        totalJobs: 100,
        pendingJobs: 65,
    );
    $oldHigh = horizonBatch(
        'preview-old-high',
        name: 'Oldest high progress',
        totalJobs: 100,
        pendingJobs: 15,
    );
    $finished = horizonBatch(
        'preview-finished',
        name: 'Finished batch',
        totalJobs: 100,
        pendingJobs: 0,
        finishedAt: 1_784_281_100,
    );
    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [$newestZero, $ten, $midZ, $midA, $oldHigh, $finished],
            'preview-finished' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    $overview = (new BatchRepositoryOverview($repository, app(CacheFactory::class)))->get();

    expect($overview)->toBe([
        'total' => 6,
        'active' => 5,
        'complete' => true,
        'message' => null,
        'previews' => [
            ['id' => 'preview-old-high', 'name' => 'Oldest high progress', 'progress' => 85],
            ['id' => 'preview-mid-z', 'name' => 'Thirty-five percent z', 'progress' => 35],
            ['id' => 'preview-mid-a', 'name' => 'Thirty-five percent a', 'progress' => 35],
        ],
    ]);
});
