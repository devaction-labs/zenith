<?php

declare(strict_types=1);

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use NckRtl\HorizonNewDawn\Support\RedisScript;

describe('RedisScript', function (): void {
    it('formats Lua arguments for Predis-style connections', function (): void {
        $connection = new RedisScriptConnectionStub;

        $result = RedisScript::evaluate(
            $connection,
            'return {KEYS[1], ARGV[1]}',
            1,
            'queue',
            'payload',
        );

        expect($result)->toBe('evaluated')
            ->and($connection->commands)->toBe([[
                'eval',
                [
                    'return {KEYS[1], ARGV[1]}',
                    1,
                    'queue',
                    'payload',
                ],
            ]]);
    });

    it('formats Lua arguments for PhpRedis connections', function (): void {
        $connection = new RedisScriptPhpRedisConnectionStub;

        $result = RedisScript::evaluate(
            $connection,
            'return {KEYS[1], ARGV[1]}',
            1,
            'queue',
            'payload',
        );

        expect($result)->toBe('evaluated')
            ->and($connection->commands)->toBe([[
                'eval',
                [
                    'return {KEYS[1], ARGV[1]}',
                    ['queue', 'payload'],
                    1,
                ],
            ]]);
    });

    it('supports connection doubles that expose the normalized Laravel signature', function (): void {
        $connection = new RedisScriptDirectEvalConnectionStub;

        $result = RedisScript::evaluate(
            $connection,
            'return {KEYS[1], ARGV[1]}',
            1,
            'queue',
            'payload',
        );

        expect($result)->toBe('evaluated')
            ->and($connection->evaluations)->toBe([[
                'return {KEYS[1], ARGV[1]}',
                1,
                ['queue', 'payload'],
            ]]);
    });
});

final class RedisScriptConnectionStub extends Connection
{
    /** @var array<int, array{string, array<int, mixed>}> */
    public array $commands = [];

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, $callback, $method = 'subscribe'): void {}

    /** @param array<int, mixed> $parameters */
    public function command($method, array $parameters = []): mixed
    {
        $this->commands[] = [$method, $parameters];

        return 'evaluated';
    }
}

final class RedisScriptPhpRedisConnectionStub extends PhpRedisConnection
{
    /** @var array<int, array{string, array<int, mixed>}> */
    public array $commands = [];

    public function __construct() {}

    /** @param array<int, mixed> $parameters */
    public function command($method, array $parameters = []): mixed
    {
        $this->commands[] = [$method, $parameters];

        return 'evaluated';
    }
}

final class RedisScriptDirectEvalConnectionStub extends Connection
{
    /** @var array<int, array{string, int, array<int, string>}> */
    public array $evaluations = [];

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, $callback, $method = 'subscribe'): void {}

    public function eval(
        string $script,
        int $numberOfKeys,
        string ...$arguments,
    ): string {
        $this->evaluations[] = [
            $script,
            $numberOfKeys,
            array_values($arguments),
        ];

        return 'evaluated';
    }
}
