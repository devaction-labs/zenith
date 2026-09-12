<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Support\RedisQueueName;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;

describe('RedisQueueName', function (): void {
    it('normalizes queue names for each supported Redis connection shape', function (
        Closure $connection,
        string $queue,
        string $expected,
    ): void {
        expect(is_callable([RedisQueueName::class, 'normalize']))->toBeTrue();

        expect(RedisQueueName::normalize($connection(), $queue))->toBe($expected);
    })->with([
        'standalone connection' => [
            fn (): Connection => new RedisQueueNameConnectionStub(false),
            'imports',
            'imports',
        ],
        'method-detected cluster connection' => [
            fn (): Connection => new RedisQueueNameConnectionStub(true),
            'imports',
            '{imports}',
        ],
        'PhpRedis cluster connection' => [
            fn (): Connection => new RedisQueueNamePhpRedisClusterConnectionStub,
            'imports',
            '{imports}',
        ],
        'Predis cluster connection' => [
            fn (): Connection => new RedisQueueNamePredisClusterConnectionStub,
            'imports',
            '{imports}',
        ],
        'existing hash tag' => [
            fn (): Connection => new RedisQueueNameConnectionStub(true),
            '{tenant}:imports',
            '{tenant}:imports',
        ],
        'embedded hash tag' => [
            fn (): Connection => new RedisQueueNameConnectionStub(true),
            'imports:{tenant}',
            'imports:{tenant}',
        ],
    ]);
});

final class RedisQueueNameConnectionStub extends Connection
{
    public function __construct(private readonly bool $cluster) {}

    public function isCluster(): bool
    {
        return $this->cluster;
    }

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, $callback, $method = 'subscribe'): void {}
}

final class RedisQueueNamePhpRedisClusterConnectionStub extends PhpRedisClusterConnection
{
    public function __construct() {}
}

final class RedisQueueNamePredisClusterConnectionStub extends PredisClusterConnection
{
    public function __construct() {}
}
