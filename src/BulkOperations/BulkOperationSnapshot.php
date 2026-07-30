<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\BulkOperations;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use InvalidArgumentException;

/**
 * Point-in-time bulk-operation target sets stored on the Horizon Redis connection.
 *
 * Continuations read a bounded page from a private sorted set so live mutations
 * to Horizon source sets cannot shift, skip, or endlessly extend the operation
 * boundary. Members remain until acknowledged after a terminal outcome so a
 * failed chunk can be retried without losing unprocessed work.
 *
 * Temporary keys use an idle TTL that is renewed on every active access. Active
 * operations therefore are not limited by total runtime or target count; state
 * is removed only at completion or after being abandoned without renewals.
 */
final class BulkOperationSnapshot
{
    public const int CHUNK_SIZE = 100;

    /**
     * Idle abandonment TTL. Renewed on every peek, acknowledge, and write so
     * long-running or backlogged continuations stay alive while workers progress.
     */
    public const int IDLE_TTL_SECONDS = 604_800;

    public function __construct(private RedisFactory $redis) {}

    public function createFromSortedSet(string $sourceKey): string
    {
        $sourceKey = trim($sourceKey);

        if ($sourceKey === '') {
            throw new InvalidArgumentException('A non-empty Redis sorted-set key is required.');
        }

        $operationId = $this->newOperationId();
        $connection = $this->connection();
        $targetsKey = $this->targetsKey($operationId);

        // Prefer prefix-aware commands. Raw zrangestore bypasses Predis/PhpRedis
        // key prefixes, so Horizon-prefixed sources like failed_jobs copy empty.
        $members = $connection->zrange($sourceKey, 0, -1, ['withscores' => true]);

        if (is_array($members) && $members !== []) {
            $pairs = [];

            foreach ($members as $member => $score) {
                if (! is_string($member) || ! is_numeric($score)) {
                    continue;
                }

                $pairs[$member] = (float) $score;
            }

            foreach (array_chunk($pairs, self::CHUNK_SIZE, true) as $chunk) {
                foreach ($chunk as $member => $score) {
                    $connection->zadd($targetsKey, $score, $member);
                }
            }
        }

        $this->initializeMeta($connection, $operationId);
        $this->renew($operationId);

        return $operationId;
    }

    /**
     * @param  iterable<int, string>  $ids
     */
    public function createFromIds(iterable $ids): string
    {
        $operationId = $this->newOperationId();
        $connection = $this->connection();
        $targetsKey = $this->targetsKey($operationId);
        $score = 0;
        $pending = 0;

        foreach ($ids as $id) {
            if ($id === '') {
                continue;
            }

            $connection->zadd($targetsKey, $score, $id);
            $score++;
            $pending++;

            if ($pending >= self::CHUNK_SIZE) {
                $this->renew($operationId);
                $pending = 0;
            }
        }

        $this->initializeMeta($connection, $operationId);
        $this->renew($operationId);

        return $operationId;
    }

    /**
     * Peek the next unprocessed page of snapshot members without removing them.
     *
     * @return list<string>
     */
    public function nextChunk(string $operationId): array
    {
        $connection = $this->connection();
        $this->ensureExists($connection, $operationId);
        $this->renew($operationId);

        $ids = $connection->zrange(
            $this->targetsKey($operationId),
            0,
            self::CHUNK_SIZE - 1,
        );

        if (! is_array($ids) || $ids === []) {
            return [];
        }

        return array_values(array_filter(
            $ids,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));
    }

    /**
     * Remove members only after a terminal outcome (successful mutation or a
     * permanent skip). Unacknowledged members remain for a retried chunk.
     */
    public function acknowledge(string $operationId, string ...$ids): void
    {
        if ($ids === []) {
            return;
        }

        $connection = $this->connection();
        $this->ensureExists($connection, $operationId);
        $connection->zrem($this->targetsKey($operationId), ...$ids);
        $this->renew($operationId);
    }

    public function hasMore(string $operationId): bool
    {
        $connection = $this->connection();
        $this->ensureExists($connection, $operationId);

        return (int) $connection->zcard($this->targetsKey($operationId)) > 0;
    }

    public function addAffected(string $operationId, int $affected): int
    {
        if ($affected < 0) {
            throw new InvalidArgumentException('Affected count cannot be negative.');
        }

        $connection = $this->connection();
        $this->ensureExists($connection, $operationId);

        if ($affected === 0) {
            return $this->totalAffected($operationId);
        }

        $total = (int) $connection->hincrby(
            $this->metaKey($operationId),
            'affected',
            $affected,
        );
        $this->renew($operationId);

        return $total;
    }

    public function totalAffected(string $operationId): int
    {
        $connection = $this->connection();
        $this->ensureExists($connection, $operationId);

        return max(0, (int) $connection->hget($this->metaKey($operationId), 'affected'));
    }

    public function renew(string $operationId): void
    {
        $connection = $this->connection();
        $ttl = self::IDLE_TTL_SECONDS;

        foreach ($this->keys($operationId) as $key) {
            $connection->expire($key, $ttl);
        }
    }

    public function cleanup(string $operationId): void
    {
        $this->connection()->del(...$this->keys($operationId));
    }

    public function finish(string $operationId): int
    {
        $total = $this->totalAffected($operationId);
        $this->cleanup($operationId);

        return $total;
    }

    private function initializeMeta(Connection $connection, string $operationId): void
    {
        $connection->hmset($this->metaKey($operationId), [
            'affected' => '0',
        ]);
    }

    private function ensureExists(Connection $connection, string $operationId): void
    {
        if (! $connection->exists($this->metaKey($operationId))) {
            throw new BulkOperationMissingStateException($operationId);
        }
    }

    private function newOperationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return list<string> */
    private function keys(string $operationId): array
    {
        return [
            $this->targetsKey($operationId),
            $this->metaKey($operationId),
        ];
    }

    private function targetsKey(string $operationId): string
    {
        return $this->prefix($operationId).':targets';
    }

    private function metaKey(string $operationId): string
    {
        return $this->prefix($operationId).':meta';
    }

    private function prefix(string $operationId): string
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/', $operationId)) {
            throw new InvalidArgumentException('Invalid bulk operation id.');
        }

        return "\x1fhorizon-new-dawn:v1:bulk-op:{$operationId}";
    }

    private function connection(): Connection
    {
        return $this->redis->connection('horizon');
    }
}
