<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

use DevactionLabs\Zenith\Jobs\ForgetsPendingJob;
use DevactionLabs\Zenith\Support\RedisScript;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * Removes pending/reserved Horizon job hashes for one connection+queue pair.
 *
 * Unlike Horizon's JobRepository::purge($queue), this never matches by queue
 * name alone, so identically named queues on other connections stay intact.
 */
final readonly class ClearQueueMetadata implements ClearsQueueMetadata, ForgetsPendingJob
{
    public function __construct(private RedisFactory $redis) {}

    public function purgePending(string $connection, string $queue): int
    {
        $count = 0;
        $cursor = '0';
        $redis = $this->horizonConnection();
        $prefix = $this->prefix();

        do {
            $result = RedisScript::evaluate(
                $redis,
                $this->purgeScript(),
                2,
                'pending_jobs',
                'recent_jobs',
                $prefix,
                $queue,
                $connection,
                $cursor,
            );

            if (! is_array($result) || count($result) < 2) {
                break;
            }

            $count += $this->integerValue($result[0]);
            $cursor = is_scalar($result[1]) ? (string) $result[1] : '0';
        } while ($cursor !== '0');

        return $count;
    }

    /** @param array<int, string> $tags */
    public function forgetPending(string $id, array $tags): bool
    {
        $removed = RedisScript::evaluate(
            $this->horizonConnection(),
            <<<'LUA'
                local hashkey = ARGV[1] .. ARGV[2]

                if redis.call('hget', hashkey, 'status') ~= 'pending' then
                    return 0
                end

                redis.call('zrem', KEYS[1], ARGV[2])
                redis.call('zrem', KEYS[2], ARGV[2])

                for i = 3, #ARGV do
                    redis.call('zrem', ARGV[1] .. ARGV[i], ARGV[2])
                end

                redis.call('del', hashkey)

                return 1
            LUA,
            2,
            'pending_jobs',
            'recent_jobs',
            $this->prefix(),
            $id,
            ...$tags,
        );

        return $this->integerValue($removed) === 1;
    }

    private function horizonConnection(): Connection
    {
        return $this->redis->connection('horizon');
    }

    private function prefix(): string
    {
        $prefix = config('horizon.prefix', 'horizon:');

        return is_string($prefix) ? $prefix : '';
    }

    private function integerValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function purgeScript(): string
    {
        return <<<'LUA'
            local count = 0
            local cursor = ARGV[4]

            local scanner = redis.call('zscan', KEYS[1], cursor, 'COUNT', 1000)
            cursor = scanner[1]

            for i = 1, #scanner[2], 2 do
                local jobid = scanner[2][i]
                local hashkey = ARGV[1] .. jobid
                local job = redis.call('hmget', hashkey, 'status', 'queue', 'connection', 'payload')

                if ((job[1] == 'reserved' or job[1] == 'pending') and job[2] == ARGV[2] and job[3] == ARGV[3]) then
                    redis.call('zrem', KEYS[1], jobid)
                    redis.call('zrem', KEYS[2], jobid)

                    if job[4] then
                        local decoded, payload = pcall(cjson.decode, job[4])

                        if decoded and type(payload) == 'table' and type(payload['tags']) == 'table' then
                            for _, tag in ipairs(payload['tags']) do
                                if type(tag) == 'string' then
                                    redis.call('zrem', ARGV[1] .. tag, jobid)
                                end
                            end
                        end
                    end

                    redis.call('del', hashkey)
                    count = count + 1
                end
            end

            return {count, cursor}
LUA;
    }
}
