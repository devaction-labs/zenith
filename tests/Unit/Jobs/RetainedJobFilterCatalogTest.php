<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\JobRepository;
use NckRtl\HorizonNewDawn\Jobs\RetainedJobFilterCatalog;
use NckRtl\HorizonNewDawn\Jobs\RetainedJobIndex;
use NckRtl\HorizonNewDawn\Jobs\RetainedJobType;

use function NckRtl\HorizonNewDawn\Tests\Support\dashboardExpects;
use function NckRtl\HorizonNewDawn\Tests\Support\mockDashboardContract;

it('shares each retained job filter catalog for one configured poll interval', function (
    RetainedJobType $type,
): void {
    config()->set('horizon-new-dawn.poll_interval', 5000);

    $payload = [
        'available' => true,
        'jobs' => [
            [
                'value' => 'App\\Jobs\\ProductionOnly',
                'label' => 'ProductionOnly',
            ],
        ],
        'queues' => ['reports'],
        'connections' => ['redis'],
        'message' => null,
    ];
    $store = mockDashboardContract(CacheRepository::class);
    dashboardExpects(
        $store,
        'remember',
        [
            "horizon-new-dawn:retained-job-filter-catalog:v1:{$type->value}",
            5,
            Mockery::on(static fn (mixed $value): bool => $value instanceof Closure),
        ],
        'once',
        value: $payload,
    );
    $cache = mockDashboardContract(CacheFactory::class);
    dashboardExpects($cache, 'store', times: 'once', value: $store);
    $index = new RetainedJobIndex(
        mockDashboardContract(RedisFactory::class),
        mockDashboardContract(JobRepository::class),
    );
    $catalog = new RetainedJobFilterCatalog(
        index: $index,
        cache: $cache,
    );

    expect($catalog->for($type)->toArray())->toBe($payload);
})->with([
    'pending jobs' => [RetainedJobType::Pending],
    'completed jobs' => [RetainedJobType::Completed],
    'silenced jobs' => [RetainedJobType::Silenced],
    'failed jobs' => [RetainedJobType::Failed],
]);
