<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Support\RedisClusterDetector;

describe('RedisClusterDetector', function (): void {
    it('is disabled when no Redis Cluster connections are configured', function (): void {
        config()->set('database.redis.clusters', null);

        expect(RedisClusterDetector::enabled())->toBeFalse();
    });

    it('detects a configured Redis Cluster connection map', function (): void {
        config()->set('database.redis.clusters', [
            'default' => [
                [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                ],
            ],
        ]);

        expect(RedisClusterDetector::enabled())->toBeTrue();
    });
});
