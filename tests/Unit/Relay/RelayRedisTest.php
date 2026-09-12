<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Relay\Relay;
use DevactionLabs\Zenith\Relay\RelayFailedException;
use DevactionLabs\Zenith\Relay\RelayTimeoutException;
use DevactionLabs\Zenith\Support\RedisScript;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Sleep;
use Laravel\Horizon\Horizon;
use Symfony\Component\Process\Process;
use Workbench\App\Jobs\FailingJob;
use Workbench\App\Jobs\SucceedingJob;

function relayRedisIsConfigured(): bool
{
    return is_string(getenv('HORIZON_COMPATIBILITY_REDIS_HOST'))
        && is_string(getenv('HORIZON_COMPATIBILITY_REDIS_PORT'))
        && is_string(getenv('HORIZON_COMPATIBILITY_REDIS_DB'));
}

/** @return array<string, string> */
function relayRedisEnvironment(): array
{
    $host = getenv('HORIZON_COMPATIBILITY_REDIS_HOST');
    $port = getenv('HORIZON_COMPATIBILITY_REDIS_PORT');
    $database = getenv('HORIZON_COMPATIBILITY_REDIS_DB');
    $client = getenv('HORIZON_COMPATIBILITY_REDIS_CLIENT');

    if (! is_string($host) || ! in_array($host, ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Set HORIZON_COMPATIBILITY_REDIS_HOST to localhost or 127.0.0.1.');
    }

    if (! is_string($port) || filter_var($port, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException('Set HORIZON_COMPATIBILITY_REDIS_PORT to an isolated local Redis port.');
    }

    if (! is_string($database) || filter_var($database, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException('Set HORIZON_COMPATIBILITY_REDIS_DB to an isolated Redis database.');
    }

    $prefix = 'zenith_relay_'.bin2hex(random_bytes(6)).'_';

    return [
        'APP_ENV' => 'local',
        'CACHE_PREFIX' => $prefix.'cache:',
        'CACHE_STORE' => 'redis',
        'HORIZON_PREFIX' => $prefix.'horizon:',
        'QUEUE_CONNECTION' => 'redis',
        'QUEUE_FAILED_DRIVER' => 'null',
        'REDIS_CACHE_DB' => $database,
        'REDIS_CLIENT' => is_string($client) && $client !== '' ? $client : 'predis',
        'REDIS_DB' => $database,
        'REDIS_HOST' => $host,
        'REDIS_PORT' => $port,
        'REDIS_PREFIX' => $prefix.'database:',
    ];
}

/** @param array<string, string> $environment */
function configureRelayRedis(array $environment): void
{
    foreach (['default', 'cache'] as $connection) {
        config([
            "database.redis.{$connection}.host" => $environment['REDIS_HOST'],
            "database.redis.{$connection}.port" => $environment['REDIS_PORT'],
            "database.redis.{$connection}.database" => $environment['REDIS_DB'],
        ]);
    }

    config([
        'cache.default' => 'redis',
        'cache.prefix' => $environment['CACHE_PREFIX'],
        'database.redis.client' => $environment['REDIS_CLIENT'],
        'database.redis.options.prefix' => $environment['REDIS_PREFIX'],
        'horizon.prefix' => $environment['HORIZON_PREFIX'],
        'horizon.use' => 'default',
        'queue.default' => 'redis',
        'queue.connections.redis.connection' => 'default',
        'queue.connections.redis.queue' => 'default',
    ]);

    Horizon::use('default');
    app()->forgetInstance('redis');
    app(CacheManager::class)->forgetDriver('redis');
}

/** @param array<string, string> $environment */
function forgetRelayRedisKeys(array $environment): void
{
    RedisScript::evaluate(
        app(RedisFactory::class)->connection('default'),
        "for _, pattern in ipairs(ARGV) do for _, key in ipairs(redis.call('keys', pattern)) do redis.call('del', key) end end return 0",
        0,
        $environment['REDIS_PREFIX'].'*',
        $environment['HORIZON_PREFIX'].'*',
    );
}

it('awaits results that a separate worker produces over real Redis', function (): void {
    $environment = relayRedisEnvironment();
    configureRelayRedis($environment);

    $succeeded = Relay::async(new SucceedingJob);
    $failed = Relay::async(new FailingJob);

    $worker = new Process(
        [
            PHP_BINARY,
            'vendor/bin/testbench',
            'queue:work',
            'redis',
            '--queue=default',
            '--stop-when-empty',
            '--tries=1',
            '--sleep=0',
            '--no-interaction',
        ],
        dirname(__DIR__, 3),
        $environment,
    );
    $worker->setTimeout(60);

    Sleep::fake();

    try {
        expect(fn () => Relay::await($succeeded, seconds: 0))->toThrow(RelayTimeoutException::class);

        $worker->start();

        expect(Relay::await($succeeded, seconds: 30))->toBeNull();

        $failure = null;

        try {
            Relay::await($failed, seconds: 30);
        } catch (RelayFailedException $exception) {
            $failure = $exception;
        }

        expect($failure?->exceptionClass)->toBe(RuntimeException::class)
            ->and($failure?->getMessage())->toBe('Intentional Workbench failure.')
            ->and($worker->wait())->toBe(0, $worker->getErrorOutput());

        Sleep::assertNeverSlept();
    } finally {
        $worker->stop(10);
        forgetRelayRedisKeys($environment);
    }
})->skip(
    ! relayRedisIsConfigured(),
    'Set HORIZON_COMPATIBILITY_REDIS_HOST, _PORT and _DB to await relays over real Redis.',
);
