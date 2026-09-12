<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\FailedJobs;

use Closure;
use DevactionLabs\Zenith\Support\RedisScript;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

final readonly class FailedJobRetryLock
{
    private const int LOCK_SECONDS = 60;

    private const string ACQUIRE_SCRIPT = <<<'LUA'
return redis.call('set', KEYS[1], ARGV[1], 'EX', ARGV[2], 'NX')
LUA;

    private const string RELEASE_SCRIPT = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end

return 0
LUA;

    public function __construct(private RedisFactory $redis) {}

    /** @param Closure(): bool $callback */
    public function run(string $id, Closure $callback): bool
    {
        $connection = $this->redis->connection('horizon');
        $key = $this->key($id);
        $token = bin2hex(random_bytes(16));
        $acquired = RedisScript::evaluate(
            $connection,
            self::ACQUIRE_SCRIPT,
            1,
            $key,
            $token,
            (string) self::LOCK_SECONDS,
        );

        if (! $this->acquired($acquired)) {
            return false;
        }

        try {
            return $callback();
        } finally {
            try {
                RedisScript::evaluate(
                    $connection,
                    self::RELEASE_SCRIPT,
                    1,
                    $key,
                    $token,
                );
            } catch (Throwable) {
                // The short expiry remains the cleanup fallback.
            }
        }
    }

    private function acquired(mixed $result): bool
    {
        if ($result === true || $result === 1 || $result === 'OK') {
            return true;
        }

        return is_object($result)
            && method_exists($result, '__toString')
            && (string) $result === 'OK';
    }

    private function key(string $id): string
    {
        return "\x1fzenith:v1:failed-job-retry-lock:"
            .hash('sha256', $id);
    }
}
