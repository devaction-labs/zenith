<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Batches\BatchesData;
use DevactionLabs\Zenith\Batches\BatchFilterCatalog;
use DevactionLabs\Zenith\Batches\BatchJobsData;
use DevactionLabs\Zenith\Jobs\JobsData;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laravel\Horizon\Contracts\JobRepository;

use function DevactionLabs\Zenith\Tests\Support\dashboardExpects;
use function DevactionLabs\Zenith\Tests\Support\dashboardReturnsUsing;
use function DevactionLabs\Zenith\Tests\Support\horizonBatch;
use function DevactionLabs\Zenith\Tests\Support\mockDashboardContract;

afterEach(function (): void {
    app(CacheFactory::class)->store()->clear();
});

it('scans retained batch pages into distinct sorted queue and connection filters', function (): void {
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis.queue', 'default');

    $recent = horizonBatch('batch-z');
    $recent->options = ['connection' => 'redis', 'queue' => 'imports'];

    $older = horizonBatch('batch-y');
    $older->options = ['connection' => 'database', 'queue' => 'reports'];

    $duplicates = horizonBatch('batch-x');
    $duplicates->options = ['connection' => 'redis', 'queue' => 'imports'];

    $nulls = horizonBatch('batch-w');
    $nulls->options = ['connection' => '', 'queue' => ''];

    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [$recent],
            'batch-z' => [$older, $duplicates, $nulls],
            'batch-w' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    $catalog = (new BatchFilterCatalog(
        $repository,
        catalogBatchData($repository),
        app(CacheFactory::class),
    ))->get();

    expect($catalog->available)->toBeTrue()
        ->and($catalog->queues)->toBe(['default', 'imports', 'reports'])
        ->and($catalog->connections)->toBe(['database', 'redis']);
});

it('excludes null connections while keeping the default queue fallback', function (): void {
    config()->set('queue.default', null);
    config()->set('queue.connections', []);

    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [tap(horizonBatch('batch-z'), function ($batch): void {
                $batch->options = ['connection' => '', 'queue' => ''];
            })],
            'batch-z' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    $catalog = (new BatchFilterCatalog(
        $repository,
        catalogBatchData($repository),
        app(CacheFactory::class),
    ))->get();

    expect($catalog->available)->toBeTrue()
        ->and($catalog->queues)->toBe(['default'])
        ->and($catalog->connections)->toBe([]);
});

it('returns an unavailable catalog when the repository repeats its final cursor', function (): void {
    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [horizonBatch('batch-z')],
            'batch-z' => [horizonBatch('batch-z')],
            default => [],
        },
    );

    $catalog = (new BatchFilterCatalog(
        $repository,
        catalogBatchData($repository),
        app(CacheFactory::class),
    ))->get();

    expect($catalog->available)->toBeFalse()
        ->and($catalog->queues)->toBe([])
        ->and($catalog->connections)->toBe([]);
});

it('returns an unavailable catalog when the repository ends a page with a blank cursor', function (): void {
    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [horizonBatch('batch-z'), horizonBatch('')],
            default => [],
        },
    );

    $catalog = (new BatchFilterCatalog(
        $repository,
        catalogBatchData($repository),
        app(CacheFactory::class),
    ))->get();

    expect($catalog->available)->toBeFalse()
        ->and($catalog->queues)->toBe([])
        ->and($catalog->connections)->toBe([]);
});

it('returns an unavailable catalog when the repository cursor increases on a later page', function (): void {
    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [horizonBatch('batch-z'), horizonBatch('batch-y')],
            'batch-y' => [horizonBatch('batch-z')],
            default => [],
        },
    );

    $catalog = (new BatchFilterCatalog(
        $repository,
        catalogBatchData($repository),
        app(CacheFactory::class),
    ))->get();

    expect($catalog->available)->toBeFalse()
        ->and($catalog->queues)->toBe([])
        ->and($catalog->connections)->toBe([]);
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
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [tap(horizonBatch('batch-z'), function ($batch): void {
                $batch->options = ['connection' => 'redis', 'queue' => 'imports'];
            })],
            'batch-z' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    $store = mockDashboardContract(CacheRepository::class);
    dashboardExpects(
        $store,
        'remember',
        [
            'zenith:batch-filter-catalog:v1',
            $expectedCacheSeconds,
            Mockery::on(static fn (mixed $value): bool => $value instanceof Closure),
        ],
        'once',
        returnUsing: static fn (string $key, int $seconds, Closure $callback): array => $callback(),
    );

    $cache = mockDashboardContract(CacheFactory::class);
    dashboardExpects($cache, 'store', times: 'once', value: $store);

    $catalog = new BatchFilterCatalog($repository, catalogBatchData($repository), $cache);

    expect($catalog->get()->toArray())->toBe([
        'available' => true,
        'complete' => true,
        'message' => null,
        'queues' => ['imports'],
        'connections' => ['redis'],
    ]);
})->with([
    'configured 1500ms interval' => [1500, 1],
    'cached config without the interval' => [null, 5],
]);

it('shares a normalized cached catalog for one poll interval and rebuilds invalid payloads', function (): void {
    config()->set('zenith.poll_interval', 5000);

    $repository = mockDashboardContract(BatchRepository::class);
    $calls = 0;
    dashboardReturnsUsing(
        $repository,
        'get',
        static function (int $limit, ?string $before) use (&$calls): array {
            $calls++;

            return match ($before) {
                null => [tap(horizonBatch('batch-z'), function ($batch): void {
                    $batch->options = ['connection' => 'redis', 'queue' => 'imports'];
                })],
                'batch-z' => [],
                default => throw new LogicException("Unexpected batch cursor [{$before}]."),
            };
        },
    );

    $cache = app(CacheFactory::class)->store();
    $first = (new BatchFilterCatalog($repository, catalogBatchData($repository), app(CacheFactory::class)))->get();
    $second = (new BatchFilterCatalog($repository, catalogBatchData($repository), app(CacheFactory::class)))->get();

    $cache->put('zenith:batch-filter-catalog:v1', [
        'available' => true,
        'complete' => true,
        'message' => null,
        'queues' => ['imports', 123],
        'connections' => ['redis'],
    ], 5);

    $third = (new BatchFilterCatalog($repository, catalogBatchData($repository), app(CacheFactory::class)))->get();

    expect($first->toArray())->toBe([
        'available' => true,
        'complete' => true,
        'message' => null,
        'queues' => ['imports'],
        'connections' => ['redis'],
    ])->and($second->toArray())->toBe($first->toArray())
        ->and($third->toArray())->toBe($first->toArray())
        ->and($calls)->toBe(4);
});

it('falls back to a repository-built catalog when the cache store fails', function (): void {
    $repository = mockDashboardContract(BatchRepository::class);
    dashboardReturnsUsing(
        $repository,
        'get',
        static fn (int $limit, ?string $before): array => match ($before) {
            null => [tap(horizonBatch('batch-z'), function ($batch): void {
                $batch->options = ['connection' => 'redis', 'queue' => 'imports'];
            })],
            'batch-z' => [],
            default => throw new LogicException("Unexpected batch cursor [{$before}]."),
        },
    );

    $failingStore = mockDashboardContract(CacheRepository::class);
    dashboardExpects($failingStore, 'remember', times: 'never');
    $cache = mockDashboardContract(CacheFactory::class);
    dashboardExpects(
        $cache,
        'store',
        times: 'once',
        exception: new RuntimeException('cache store unavailable'),
    );

    $catalog = (new BatchFilterCatalog(
        $repository,
        catalogBatchData($repository),
        $cache,
    ))->get();

    expect($catalog->toArray())->toBe([
        'available' => true,
        'complete' => true,
        'message' => null,
        'queues' => ['imports'],
        'connections' => ['redis'],
    ]);
});

it('returns every queue and connection from a multipage retained scan', function (): void {
    config()->set('zenith.poll_interval', 0);
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
                    $batch = horizonBatch(sprintf('batch-%04d', $index));
                    $batch->options = match ($index) {
                        1001 => ['connection' => 'redis', 'queue' => 'imports'],
                        500 => ['connection' => 'database', 'queue' => 'reports'],
                        1 => ['connection' => 'sqs', 'queue' => 'mail'],
                        default => ['connection' => 'redis', 'queue' => 'imports'],
                    };

                    return $batch;
                },
                range($start, $end),
            );
        },
    );

    $catalog = (new BatchFilterCatalog(
        $repository,
        catalogBatchData($repository),
        app(CacheFactory::class),
    ))->get();

    expect($catalog->toArray())->toBe([
        'available' => true,
        'complete' => true,
        'message' => null,
        'queues' => ['imports', 'mail', 'reports'],
        'connections' => ['database', 'redis', 'sqs'],
    ])->and($calls)->toBe(12);
});

function catalogBatchData(BatchRepository $repository): BatchesData
{
    $jobs = mockDashboardContract(JobRepository::class);

    return new BatchesData(
        $repository,
        new BatchJobsData($jobs, new JobsData($jobs)),
    );
}
