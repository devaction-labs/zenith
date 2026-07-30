<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Jobs;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use JsonException;
use NckRtl\HorizonNewDawn\Support\RedisQueueName;
use NckRtl\HorizonNewDawn\Support\RedisScript;
use RuntimeException;
use Throwable;

final readonly class PendingJobStateIndex implements PendingJobEntryScanner
{
    private const int SCAN_CHUNK_SIZE = 500;

    private const int SNAPSHOT_TTL_SECONDS = 120;

    private const string CREATE_SNAPSHOT_SCRIPT = <<<'LUA'
        local timestamp = redis.call('time')
        local asOf = timestamp[1]
        local state = ARGV[1]
        local ttl = ARGV[2]
        local listCount = 0
        local sortedCount = 0

        redis.call('del', KEYS[4], KEYS[5])

        if state == 'ready' then
            redis.call('copy', KEYS[2], KEYS[4], 'replace')
            listCount = redis.call('llen', KEYS[4])

            if listCount > 0 then
                redis.call('expire', KEYS[4], ttl)
            end
        elseif state == 'reserved' then
            redis.call('copy', KEYS[3], KEYS[5], 'replace')
            sortedCount = redis.call('zcard', KEYS[5])

            if sortedCount > 0 then
                redis.call('expire', KEYS[5], ttl)
            end
        elseif state == 'delayed' then
            sortedCount = redis.call(
                'zrangestore',
                KEYS[5],
                KEYS[3],
                '(' .. asOf,
                '+inf',
                'byscore'
            )

            if sortedCount > 0 then
                redis.call('expire', KEYS[5], ttl)
            end
        elseif state == 'released' then
            redis.call('copy', KEYS[2], KEYS[4], 'replace')
            listCount = redis.call('llen', KEYS[4])

            if listCount > 0 then
                redis.call('expire', KEYS[4], ttl)
            end

            sortedCount = redis.call(
                'zrangestore',
                KEYS[5],
                KEYS[3],
                '-inf',
                asOf,
                'byscore'
            )

            if sortedCount > 0 then
                redis.call('expire', KEYS[5], ttl)
            end
        else
            return redis.error_reply('Unsupported pending job state')
        end

        local guard = asOf .. ':' .. listCount .. ':' .. sortedCount
        redis.call('set', KEYS[1], guard, 'ex', ttl)

        return {
            tostring(asOf),
            tostring(listCount),
            tostring(sortedCount),
            guard
        }
        LUA;

    private const string READ_SNAPSHOT_SCRIPT = <<<'LUA'
        local guard = redis.call('get', KEYS[1])

        if not guard or guard ~= ARGV[4] then
            return false
        end

        local payloads

        if ARGV[3] == 'list' then
            payloads = redis.call('lrange', KEYS[2], ARGV[1], ARGV[2])
        elseif ARGV[3] == 'sorted_scores' then
            payloads = redis.call('zrange', KEYS[2], ARGV[1], ARGV[2], 'withscores')
        else
            payloads = redis.call('zrange', KEYS[2], ARGV[1], ARGV[2])
        end

        redis.call('expire', KEYS[1], ARGV[5])

        if redis.call('exists', KEYS[3]) == 1 then
            redis.call('expire', KEYS[3], ARGV[5])
        end

        if redis.call('exists', KEYS[4]) == 1 then
            redis.call('expire', KEYS[4], ARGV[5])
        end

        return {guard, payloads}
        LUA;

    /**
     * Atomically snapshot ready + reserved + delayed structures at one Redis TIME.
     *
     * KEYS: guard, readySource, reservedSource, delayedSource,
     *       readySnap, reservedSnap, delayedSnap, releasedDelayedSnap
     * ARGV: ttl
     */
    private const string CREATE_PENDING_QUEUE_SNAPSHOT_SCRIPT = <<<'LUA'
        local timestamp = redis.call('time')
        local asOf = timestamp[1]
        local ttl = ARGV[1]

        redis.call('del', KEYS[5], KEYS[6], KEYS[7], KEYS[8])

        redis.call('copy', KEYS[2], KEYS[5], 'replace')
        local readyCount = redis.call('llen', KEYS[5])

        if readyCount > 0 then
            redis.call('expire', KEYS[5], ttl)
        end

        redis.call('copy', KEYS[3], KEYS[6], 'replace')
        local reservedCount = redis.call('zcard', KEYS[6])

        if reservedCount > 0 then
            redis.call('expire', KEYS[6], ttl)
        end

        local delayedCount = redis.call(
            'zrangestore',
            KEYS[7],
            KEYS[4],
            '(' .. asOf,
            '+inf',
            'byscore'
        )

        if delayedCount > 0 then
            redis.call('expire', KEYS[7], ttl)
        end

        local releasedDelayedCount = redis.call(
            'zrangestore',
            KEYS[8],
            KEYS[4],
            '-inf',
            asOf,
            'byscore'
        )

        if releasedDelayedCount > 0 then
            redis.call('expire', KEYS[8], ttl)
        end

        local guard = asOf
            .. ':' .. readyCount
            .. ':' .. reservedCount
            .. ':' .. delayedCount
            .. ':' .. releasedDelayedCount
        redis.call('set', KEYS[1], guard, 'ex', ttl)

        return {
            tostring(asOf),
            tostring(readyCount),
            tostring(reservedCount),
            tostring(delayedCount),
            tostring(releasedDelayedCount),
            guard
        }
        LUA;

    /**
     * KEYS: guard, dataKey, readySnap, reservedSnap, delayedSnap, releasedDelayedSnap
     * ARGV: start, stop, kind, guard, ttl
     */
    private const string READ_PENDING_QUEUE_SNAPSHOT_SCRIPT = <<<'LUA'
        local guard = redis.call('get', KEYS[1])

        if not guard or guard ~= ARGV[4] then
            return false
        end

        local payloads

        if ARGV[3] == 'list' then
            payloads = redis.call('lrange', KEYS[2], ARGV[1], ARGV[2])
        elseif ARGV[3] == 'sorted_scores' then
            payloads = redis.call('zrange', KEYS[2], ARGV[1], ARGV[2], 'withscores')
        else
            payloads = redis.call('zrange', KEYS[2], ARGV[1], ARGV[2])
        end

        redis.call('expire', KEYS[1], ARGV[5])

        for i = 3, 6 do
            if redis.call('exists', KEYS[i]) == 1 then
                redis.call('expire', KEYS[i], ARGV[5])
            end
        end

        return {guard, payloads}
        LUA;

    public function __construct(private QueueFactory $queues) {}

    /**
     * @param  array<int, array{connection: string, queue: string}>  $targets
     * @return array<string, true>
     */
    public function matchingIds(array $targets, string $state): array
    {
        $matches = [];

        foreach ($this->matchingEntries($targets, $state) as $entry) {
            $matches[$entry['id']] = true;
        }

        return $matches;
    }

    /**
     * @param  array{connection: string, queue: string}  $target
     * @return list<array{
     *     id: string,
     *     state: 'ready'|'reserved'|'delayed'|'released',
     *     connection: string,
     *     queue: string,
     *     payload: array<string, mixed>,
     *     score: float|null
     * }>
     */
    public function pendingQueueEntries(array $target): array
    {
        $queue = $this->queues->connection($target['connection']);

        if (! $queue instanceof RedisQueue) {
            throw new RuntimeException(
                "Pending state is unavailable for [{$target['connection']}].",
            );
        }

        $connection = $queue->getConnection();
        $queueKey = $queue->getQueue(RedisQueueName::normalize(
            $connection,
            $target['queue'],
        ));
        $snapshot = $this->createPendingQueueSnapshot($connection, $queueKey);

        try {
            $entries = [];
            $seen = [];

            foreach ($snapshot['structures'] as $structure) {
                $this->scanPendingQueueStructure(
                    $connection,
                    $snapshot,
                    $structure,
                    $target['connection'],
                    $target['queue'],
                    $seen,
                    $entries,
                );
            }

            return $entries;
        } finally {
            $this->deletePendingQueueSnapshot($connection, $snapshot);
        }
    }

    /**
     * @param  array<int, array{connection: string, queue: string}>  $targets
     * @return list<array{
     *     id: string,
     *     connection: string,
     *     queue: string,
     *     payload: array<string, mixed>,
     *     score: float|null
     * }>
     */
    public function matchingEntries(array $targets, string $state): array
    {
        if (! in_array($state, ['ready', 'reserved', 'delayed', 'released'], true)) {
            throw new RuntimeException("Unsupported pending job state [{$state}].");
        }

        $entries = [];

        foreach ($targets as $target) {
            $queue = $this->queues->connection($target['connection']);

            if (! $queue instanceof RedisQueue) {
                throw new RuntimeException(
                    "Pending state is unavailable for [{$target['connection']}].",
                );
            }

            $connection = $queue->getConnection();
            $queueKey = $queue->getQueue(RedisQueueName::normalize(
                $connection,
                $target['queue'],
            ));
            $snapshot = $this->createSnapshot(
                $connection,
                $queueKey,
                $state,
            );

            try {
                foreach ($snapshot['structures'] as $structure) {
                    $this->scanSnapshotEntries(
                        $connection,
                        $snapshot,
                        $structure,
                        $target['connection'],
                        $target['queue'],
                        $entries,
                    );
                }
            } finally {
                $this->deleteSnapshot($connection, $snapshot);
            }
        }

        return $entries;
    }

    /**
     * Actively delayed job IDs mapped to their queue delayed zset scores
     * (availability timestamps). Proportional to delayed backlog only.
     *
     * @param  array<int, array{connection: string, queue: string}>  $targets
     * @return array<string, float>
     */
    public function delayedAvailabilityScores(array $targets): array
    {
        $scores = [];

        foreach ($targets as $target) {
            $queue = $this->queues->connection($target['connection']);

            if (! $queue instanceof RedisQueue) {
                throw new RuntimeException(
                    "Pending state is unavailable for [{$target['connection']}].",
                );
            }

            $connection = $queue->getConnection();
            $queueKey = $queue->getQueue(RedisQueueName::normalize(
                $connection,
                $target['queue'],
            ));
            $snapshot = $this->createSnapshot(
                $connection,
                $queueKey,
                'delayed',
            );

            try {
                foreach ($snapshot['structures'] as $structure) {
                    $this->scanSnapshotScores(
                        $connection,
                        $snapshot,
                        $structure,
                        $scores,
                    );
                }
            } finally {
                $this->deleteSnapshot($connection, $snapshot);
            }
        }

        return $scores;
    }

    /**
     * @return array{
     *     asOf: float,
     *     guard: string,
     *     guardKey: string,
     *     listKey: string,
     *     sortedKey: string,
     *     structures: array<int, array{
     *         key: string,
     *         kind: 'list'|'sorted',
     *         expected: int,
     *         filter: 'all'|'ready'|'released'
     *     }>
     * }
     */
    private function createSnapshot(
        Connection $connection,
        string $queueKey,
        string $state,
    ): array {
        $token = bin2hex(random_bytes(12));
        $baseKey = "{$queueKey}:horizon-new-dawn:pending-snapshot:{$token}";
        $guardKey = "{$baseKey}:guard";
        $listKey = "{$baseKey}:list";
        $sortedKey = "{$baseKey}:sorted";
        $sortedSourceKey = $state === 'reserved'
            ? "{$queueKey}:reserved"
            : "{$queueKey}:delayed";
        $snapshotKeys = [
            $guardKey,
            $listKey,
            $sortedKey,
        ];

        try {
            $result = RedisScript::evaluate(
                $connection,
                self::CREATE_SNAPSHOT_SCRIPT,
                5,
                $guardKey,
                $queueKey,
                $sortedSourceKey,
                $listKey,
                $sortedKey,
                $state,
                (string) self::SNAPSHOT_TTL_SECONDS,
            );
        } catch (Throwable $exception) {
            $this->deleteSnapshotKeys($connection, $snapshotKeys);

            throw $exception;
        }

        if (
            ! is_array($result)
            || count($result) !== 4
            || ! is_numeric($result[0] ?? null)
            || ! is_numeric($result[1] ?? null)
            || ! is_numeric($result[2] ?? null)
            || ! is_string($result[3] ?? null)
            || $result[3] === ''
        ) {
            $this->deleteSnapshotKeys($connection, $snapshotKeys);

            throw new RuntimeException(
                'The pending job state snapshot could not be created.',
            );
        }

        $asOf = (float) $result[0];
        $listCount = max(0, (int) $result[1]);
        $sortedCount = max(0, (int) $result[2]);
        $structures = match ($state) {
            'ready' => [[
                'key' => $listKey,
                'kind' => 'list',
                'expected' => $listCount,
                'filter' => 'ready',
            ]],
            'reserved', 'delayed' => [[
                'key' => $sortedKey,
                'kind' => 'sorted',
                'expected' => $sortedCount,
                'filter' => 'all',
            ]],
            'released' => [
                [
                    'key' => $listKey,
                    'kind' => 'list',
                    'expected' => $listCount,
                    'filter' => 'released',
                ],
                [
                    'key' => $sortedKey,
                    'kind' => 'sorted',
                    'expected' => $sortedCount,
                    'filter' => 'all',
                ],
            ],
            default => throw new RuntimeException(
                "Unsupported pending job state [{$state}].",
            ),
        };

        return [
            'asOf' => $asOf,
            'guard' => $result[3],
            'guardKey' => $guardKey,
            'listKey' => $listKey,
            'sortedKey' => $sortedKey,
            'structures' => $structures,
        ];
    }

    /**
     * @return array{
     *     asOf: float,
     *     guard: string,
     *     guardKey: string,
     *     readyKey: string,
     *     reservedKey: string,
     *     delayedKey: string,
     *     releasedDelayedKey: string,
     *     structures: array<int, array{
     *         key: string,
     *         kind: 'list'|'sorted',
     *         expected: int,
     *         state: 'ready'|'reserved'|'delayed'|'released',
     *         filter: 'all'|'ready'|'released'
     *     }>
     * }
     */
    private function createPendingQueueSnapshot(
        Connection $connection,
        string $queueKey,
    ): array {
        $token = bin2hex(random_bytes(12));
        $baseKey = "{$queueKey}:horizon-new-dawn:pending-queue-snapshot:{$token}";
        $guardKey = "{$baseKey}:guard";
        $readyKey = "{$baseKey}:ready";
        $reservedKey = "{$baseKey}:reserved";
        $delayedKey = "{$baseKey}:delayed";
        $releasedDelayedKey = "{$baseKey}:released-delayed";
        $snapshotKeys = [
            $guardKey,
            $readyKey,
            $reservedKey,
            $delayedKey,
            $releasedDelayedKey,
        ];

        try {
            $result = RedisScript::evaluate(
                $connection,
                self::CREATE_PENDING_QUEUE_SNAPSHOT_SCRIPT,
                8,
                $guardKey,
                $queueKey,
                "{$queueKey}:reserved",
                "{$queueKey}:delayed",
                $readyKey,
                $reservedKey,
                $delayedKey,
                $releasedDelayedKey,
                (string) self::SNAPSHOT_TTL_SECONDS,
            );
        } catch (Throwable $exception) {
            $this->deleteSnapshotKeys($connection, $snapshotKeys);

            throw $exception;
        }

        if (
            ! is_array($result)
            || count($result) !== 6
            || ! is_numeric($result[0] ?? null)
            || ! is_numeric($result[1] ?? null)
            || ! is_numeric($result[2] ?? null)
            || ! is_numeric($result[3] ?? null)
            || ! is_numeric($result[4] ?? null)
            || ! is_string($result[5] ?? null)
            || $result[5] === ''
        ) {
            $this->deleteSnapshotKeys($connection, $snapshotKeys);

            throw new RuntimeException(
                'The pending queue snapshot could not be created.',
            );
        }

        $readyCount = max(0, (int) $result[1]);
        $reservedCount = max(0, (int) $result[2]);
        $delayedCount = max(0, (int) $result[3]);
        $releasedDelayedCount = max(0, (int) $result[4]);

        return [
            'asOf' => (float) $result[0],
            'guard' => $result[5],
            'guardKey' => $guardKey,
            'readyKey' => $readyKey,
            'reservedKey' => $reservedKey,
            'delayedKey' => $delayedKey,
            'releasedDelayedKey' => $releasedDelayedKey,
            'structures' => [
                [
                    'key' => $readyKey,
                    'kind' => 'list',
                    'expected' => $readyCount,
                    'state' => 'ready',
                    'filter' => 'ready',
                ],
                [
                    'key' => $readyKey,
                    'kind' => 'list',
                    'expected' => $readyCount,
                    'state' => 'released',
                    'filter' => 'released',
                ],
                [
                    'key' => $reservedKey,
                    'kind' => 'sorted',
                    'expected' => $reservedCount,
                    'state' => 'reserved',
                    'filter' => 'all',
                ],
                [
                    'key' => $delayedKey,
                    'kind' => 'sorted',
                    'expected' => $delayedCount,
                    'state' => 'delayed',
                    'filter' => 'all',
                ],
                [
                    'key' => $releasedDelayedKey,
                    'kind' => 'sorted',
                    'expected' => $releasedDelayedCount,
                    'state' => 'released',
                    'filter' => 'all',
                ],
            ],
        ];
    }

    /**
     * @param array{
     *     asOf: float,
     *     guard: string,
     *     guardKey: string,
     *     readyKey: string,
     *     reservedKey: string,
     *     delayedKey: string,
     *     releasedDelayedKey: string,
     *     structures: array<int, array{
     *         key: string,
     *         kind: 'list'|'sorted',
     *         expected: int,
     *         state: 'ready'|'reserved'|'delayed'|'released',
     *         filter: 'all'|'ready'|'released'
     *     }>
     * } $snapshot
     * @param array{
     *     key: string,
     *     kind: 'list'|'sorted',
     *     expected: int,
     *     state: 'ready'|'reserved'|'delayed'|'released',
     *     filter: 'all'|'ready'|'released'
     * } $structure
     * @param  array<string, true>  $seen
     * @param  list<array{
     *     id: string,
     *     state: 'ready'|'reserved'|'delayed'|'released',
     *     connection: string,
     *     queue: string,
     *     payload: array<string, mixed>,
     *     score: float|null
     * }>  $entries
     */
    private function scanPendingQueueStructure(
        Connection $connection,
        array $snapshot,
        array $structure,
        string $connectionName,
        string $queueName,
        array &$seen,
        array &$entries,
    ): void {
        $start = 0;
        $read = 0;
        $includeScores = $structure['kind'] === 'sorted';

        while (true) {
            if ($includeScores) {
                $chunk = $this->readPendingQueueScoreChunk(
                    $connection,
                    $snapshot,
                    $structure,
                    $start,
                );
                $read += count($chunk);

                foreach ($chunk as $payload => $score) {
                    $this->appendPendingQueueEntry(
                        $payload,
                        $snapshot,
                        $structure,
                        $connectionName,
                        $queueName,
                        $score,
                        $seen,
                        $entries,
                    );
                }

                if (count($chunk) < self::SCAN_CHUNK_SIZE) {
                    break;
                }
            } else {
                $payloads = $this->readPendingQueueChunk(
                    $connection,
                    $snapshot,
                    $structure,
                    $start,
                );
                $read += count($payloads);

                foreach ($payloads as $payload) {
                    $this->appendPendingQueueEntry(
                        $payload,
                        $snapshot,
                        $structure,
                        $connectionName,
                        $queueName,
                        null,
                        $seen,
                        $entries,
                    );
                }

                if (count($payloads) < self::SCAN_CHUNK_SIZE) {
                    break;
                }
            }

            $start += self::SCAN_CHUNK_SIZE;
        }

        // Ready list is scanned twice (ready + released filters); only the first
        // pass validates the expected member count against the snapshot guard.
        if ($structure['state'] === 'released' && $structure['kind'] === 'list') {
            return;
        }

        if ($read !== $structure['expected']) {
            throw new RuntimeException(
                'The pending job state snapshot expired; refresh is required.',
            );
        }
    }

    /**
     * @param array{
     *     asOf: float,
     *     guard: string,
     *     guardKey: string,
     *     readyKey: string,
     *     reservedKey: string,
     *     delayedKey: string,
     *     releasedDelayedKey: string
     * } $snapshot
     * @param array{
     *     key: string,
     *     kind: 'list'|'sorted',
     *     expected: int,
     *     state: 'ready'|'reserved'|'delayed'|'released',
     *     filter: 'all'|'ready'|'released'
     * } $structure
     * @param  array<string, true>  $seen
     * @param  list<array{
     *     id: string,
     *     state: 'ready'|'reserved'|'delayed'|'released',
     *     connection: string,
     *     queue: string,
     *     payload: array<string, mixed>,
     *     score: float|null
     * }>  $entries
     */
    private function appendPendingQueueEntry(
        string $payload,
        array $snapshot,
        array $structure,
        string $connectionName,
        string $queueName,
        ?float $score,
        array &$seen,
        array &$entries,
    ): void {
        $decoded = $this->decodePayload($payload);
        $id = $decoded['uuid'] ?? $decoded['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return;
        }

        if (
            $structure['filter'] === 'ready'
            && $this->isReleased($decoded, $snapshot['asOf'])
        ) {
            return;
        }

        if (
            $structure['filter'] === 'released'
            && ! $this->isReleased($decoded, $snapshot['asOf'])
        ) {
            return;
        }

        if (isset($seen[$id])) {
            return;
        }

        $seen[$id] = true;
        $entries[] = [
            'id' => $id,
            'state' => $structure['state'],
            'connection' => $connectionName,
            'queue' => $queueName,
            'payload' => $decoded,
            'score' => $score,
        ];
    }

    /**
     * @param array{
     *     guard: string,
     *     guardKey: string,
     *     readyKey: string,
     *     reservedKey: string,
     *     delayedKey: string,
     *     releasedDelayedKey: string
     * } $snapshot
     * @param  array{key: string}  $structure
     * @return array<int, string>
     */
    private function readPendingQueueChunk(
        Connection $connection,
        array $snapshot,
        array $structure,
        int $start,
    ): array {
        $result = RedisScript::evaluate(
            $connection,
            self::READ_PENDING_QUEUE_SNAPSHOT_SCRIPT,
            6,
            $snapshot['guardKey'],
            $structure['key'],
            $snapshot['readyKey'],
            $snapshot['reservedKey'],
            $snapshot['delayedKey'],
            $snapshot['releasedDelayedKey'],
            (string) $start,
            (string) ($start + self::SCAN_CHUNK_SIZE - 1),
            'list',
            $snapshot['guard'],
            (string) self::SNAPSHOT_TTL_SECONDS,
        );

        if (
            ! is_array($result)
            || ! is_string($result[0] ?? null)
            || $result[0] !== $snapshot['guard']
            || ! is_array($result[1] ?? null)
        ) {
            throw new RuntimeException(
                'The pending job state snapshot expired; refresh is required.',
            );
        }

        return array_values(array_filter(
            $result[1],
            is_string(...),
        ));
    }

    /**
     * @param array{
     *     guard: string,
     *     guardKey: string,
     *     readyKey: string,
     *     reservedKey: string,
     *     delayedKey: string,
     *     releasedDelayedKey: string
     * } $snapshot
     * @param  array{key: string}  $structure
     * @return array<string, float>
     */
    private function readPendingQueueScoreChunk(
        Connection $connection,
        array $snapshot,
        array $structure,
        int $start,
    ): array {
        $result = RedisScript::evaluate(
            $connection,
            self::READ_PENDING_QUEUE_SNAPSHOT_SCRIPT,
            6,
            $snapshot['guardKey'],
            $structure['key'],
            $snapshot['readyKey'],
            $snapshot['reservedKey'],
            $snapshot['delayedKey'],
            $snapshot['releasedDelayedKey'],
            (string) $start,
            (string) ($start + self::SCAN_CHUNK_SIZE - 1),
            'sorted_scores',
            $snapshot['guard'],
            (string) self::SNAPSHOT_TTL_SECONDS,
        );

        if (
            ! is_array($result)
            || ! is_string($result[0] ?? null)
            || $result[0] !== $snapshot['guard']
            || ! is_array($result[1] ?? null)
        ) {
            throw new RuntimeException(
                'The pending job state snapshot expired; refresh is required.',
            );
        }

        return $this->normalizeScoredPayloads($result[1]);
    }

    /**
     * @param array{
     *     guardKey: string,
     *     readyKey: string,
     *     reservedKey: string,
     *     delayedKey: string,
     *     releasedDelayedKey: string
     * } $snapshot
     */
    private function deletePendingQueueSnapshot(
        Connection $connection,
        array $snapshot,
    ): void {
        $this->deleteSnapshotKeys($connection, [
            $snapshot['guardKey'],
            $snapshot['readyKey'],
            $snapshot['reservedKey'],
            $snapshot['delayedKey'],
            $snapshot['releasedDelayedKey'],
        ]);
    }

    /**
     * @param array{
     *     asOf: float,
     *     guard: string,
     *     guardKey: string,
     *     listKey: string,
     *     sortedKey: string,
     *     structures: array<int, array{
     *         key: string,
     *         kind: 'list'|'sorted',
     *         expected: int,
     *         filter: 'all'|'ready'|'released'
     *     }>
     * } $snapshot
     * @param array{
     *     key: string,
     *     kind: 'list'|'sorted',
     *     expected: int,
     *     filter: 'all'|'ready'|'released'
     * } $structure
     * @param  list<array{
     *     id: string,
     *     connection: string,
     *     queue: string,
     *     payload: array<string, mixed>,
     *     score: float|null
     * }>  $entries
     */
    private function scanSnapshotEntries(
        Connection $connection,
        array $snapshot,
        array $structure,
        string $connectionName,
        string $queueName,
        array &$entries,
    ): void {
        $start = 0;
        $read = 0;
        $includeScores = $structure['kind'] === 'sorted';

        while (true) {
            if ($includeScores) {
                $chunk = $this->readSnapshotScoreChunk(
                    $connection,
                    $snapshot,
                    $structure,
                    $start,
                );
                $read += count($chunk);

                foreach ($chunk as $payload => $score) {
                    $this->appendMatchingEntry(
                        $payload,
                        $snapshot,
                        $structure,
                        $connectionName,
                        $queueName,
                        $score,
                        $entries,
                    );
                }

                if (count($chunk) < self::SCAN_CHUNK_SIZE) {
                    break;
                }
            } else {
                $payloads = $this->readSnapshotChunk(
                    $connection,
                    $snapshot,
                    $structure,
                    $start,
                );
                $read += count($payloads);

                foreach ($payloads as $payload) {
                    $this->appendMatchingEntry(
                        $payload,
                        $snapshot,
                        $structure,
                        $connectionName,
                        $queueName,
                        null,
                        $entries,
                    );
                }

                if (count($payloads) < self::SCAN_CHUNK_SIZE) {
                    break;
                }
            }

            $start += self::SCAN_CHUNK_SIZE;
        }

        if ($read !== $structure['expected']) {
            throw new RuntimeException(
                'The pending job state snapshot expired; refresh is required.',
            );
        }
    }

    /**
     * @param array{
     *     asOf: float,
     *     guard: string,
     *     guardKey: string,
     *     listKey: string,
     *     sortedKey: string,
     *     structures: array<int, array{
     *         key: string,
     *         kind: 'list'|'sorted',
     *         expected: int,
     *         filter: 'all'|'ready'|'released'
     *     }>
     * } $snapshot
     * @param array{
     *     key: string,
     *     kind: 'list'|'sorted',
     *     expected: int,
     *     filter: 'all'|'ready'|'released'
     * } $structure
     * @param  list<array{
     *     id: string,
     *     connection: string,
     *     queue: string,
     *     payload: array<string, mixed>,
     *     score: float|null
     * }>  $entries
     */
    private function appendMatchingEntry(
        string $payload,
        array $snapshot,
        array $structure,
        string $connectionName,
        string $queueName,
        ?float $score,
        array &$entries,
    ): void {
        $decoded = $this->decodePayload($payload);
        $id = $decoded['uuid'] ?? $decoded['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return;
        }

        if (
            $structure['filter'] === 'ready'
            && $this->isReleased($decoded, $snapshot['asOf'])
        ) {
            return;
        }

        if (
            $structure['filter'] === 'released'
            && ! $this->isReleased($decoded, $snapshot['asOf'])
        ) {
            return;
        }

        $entries[] = [
            'id' => $id,
            'connection' => $connectionName,
            'queue' => $queueName,
            'payload' => $decoded,
            'score' => $score,
        ];
    }

    /**
     * @param array{
     *     asOf: float,
     *     guard: string,
     *     guardKey: string,
     *     listKey: string,
     *     sortedKey: string,
     *     structures: array<int, array{
     *         key: string,
     *         kind: 'list'|'sorted',
     *         expected: int,
     *         filter: 'all'|'ready'|'released'
     *     }>
     * } $snapshot
     * @param array{
     *     key: string,
     *     kind: 'list'|'sorted',
     *     expected: int,
     *     filter: 'all'|'ready'|'released'
     * } $structure
     * @param  array<string, float>  $scores
     */
    private function scanSnapshotScores(
        Connection $connection,
        array $snapshot,
        array $structure,
        array &$scores,
    ): void {
        if ($structure['kind'] !== 'sorted') {
            throw new RuntimeException(
                'Pending delayed scores require a sorted snapshot structure.',
            );
        }

        $start = 0;
        $read = 0;

        while (true) {
            $entries = $this->readSnapshotScoreChunk(
                $connection,
                $snapshot,
                $structure,
                $start,
            );
            $read += count($entries);

            foreach ($entries as $payload => $score) {
                $decoded = $this->decodePayload($payload);
                $id = $decoded['uuid'] ?? $decoded['id'] ?? null;

                if (! is_string($id) || $id === '') {
                    continue;
                }

                $scores[$id] = $score;
            }

            if (count($entries) < self::SCAN_CHUNK_SIZE) {
                break;
            }

            $start += self::SCAN_CHUNK_SIZE;
        }

        if ($read !== $structure['expected']) {
            throw new RuntimeException(
                'The pending job state snapshot expired; refresh is required.',
            );
        }
    }

    /**
     * @param array{
     *     asOf: float,
     *     guard: string,
     *     guardKey: string,
     *     listKey: string,
     *     sortedKey: string,
     *     structures: array<int, array{
     *         key: string,
     *         kind: 'list'|'sorted',
     *         expected: int,
     *         filter: 'all'|'ready'|'released'
     *     }>
     * } $snapshot
     * @param array{
     *     key: string,
     *     kind: 'list'|'sorted',
     *     expected: int,
     *     filter: 'all'|'ready'|'released'
     * } $structure
     * @return array<int, string>
     */
    private function readSnapshotChunk(
        Connection $connection,
        array $snapshot,
        array $structure,
        int $start,
    ): array {
        $result = RedisScript::evaluate(
            $connection,
            self::READ_SNAPSHOT_SCRIPT,
            4,
            $snapshot['guardKey'],
            $structure['key'],
            $snapshot['listKey'],
            $snapshot['sortedKey'],
            (string) $start,
            (string) ($start + self::SCAN_CHUNK_SIZE - 1),
            $structure['kind'],
            $snapshot['guard'],
            (string) self::SNAPSHOT_TTL_SECONDS,
        );

        if (
            ! is_array($result)
            || ! is_string($result[0] ?? null)
            || $result[0] !== $snapshot['guard']
            || ! is_array($result[1] ?? null)
        ) {
            throw new RuntimeException(
                'The pending job state snapshot expired; refresh is required.',
            );
        }

        return array_values(array_filter(
            $result[1],
            is_string(...),
        ));
    }

    /**
     * @param array{
     *     asOf: float,
     *     guard: string,
     *     guardKey: string,
     *     listKey: string,
     *     sortedKey: string,
     *     structures: array<int, array{
     *         key: string,
     *         kind: 'list'|'sorted',
     *         expected: int,
     *         filter: 'all'|'ready'|'released'
     *     }>
     * } $snapshot
     * @param array{
     *     key: string,
     *     kind: 'list'|'sorted',
     *     expected: int,
     *     filter: 'all'|'ready'|'released'
     * } $structure
     * @return array<string, float>
     */
    private function readSnapshotScoreChunk(
        Connection $connection,
        array $snapshot,
        array $structure,
        int $start,
    ): array {
        $result = RedisScript::evaluate(
            $connection,
            self::READ_SNAPSHOT_SCRIPT,
            4,
            $snapshot['guardKey'],
            $structure['key'],
            $snapshot['listKey'],
            $snapshot['sortedKey'],
            (string) $start,
            (string) ($start + self::SCAN_CHUNK_SIZE - 1),
            'sorted_scores',
            $snapshot['guard'],
            (string) self::SNAPSHOT_TTL_SECONDS,
        );

        if (
            ! is_array($result)
            || ! is_string($result[0] ?? null)
            || $result[0] !== $snapshot['guard']
            || ! is_array($result[1] ?? null)
        ) {
            throw new RuntimeException(
                'The pending job state snapshot expired; refresh is required.',
            );
        }

        return $this->normalizeScoredPayloads($result[1]);
    }

    /**
     * @param  array<int|string, mixed>  $payloads
     * @return array<string, float>
     */
    private function normalizeScoredPayloads(array $payloads): array
    {
        $normalized = [];

        if ($payloads === []) {
            return [];
        }

        // Predis/PhpRedis withscores maps: member => score
        if (! array_is_list($payloads)) {
            foreach ($payloads as $payload => $score) {
                if (is_string($payload) && is_numeric($score)) {
                    $normalized[$payload] = (float) $score;
                }
            }

            return $normalized;
        }

        // Lua withscores flat list: member, score, member, score, ...
        $count = count($payloads);

        for ($offset = 0; $offset + 1 < $count; $offset += 2) {
            $payload = $payloads[$offset];
            $score = $payloads[$offset + 1];

            if (is_string($payload) && is_numeric($score)) {
                $normalized[$payload] = (float) $score;
            }
        }

        return $normalized;
    }

    /**
     * @param array{
     *     guardKey: string,
     *     listKey: string,
     *     sortedKey: string
     * } $snapshot
     */
    private function deleteSnapshot(
        Connection $connection,
        array $snapshot,
    ): void {
        $this->deleteSnapshotKeys($connection, [
            $snapshot['guardKey'],
            $snapshot['listKey'],
            $snapshot['sortedKey'],
        ]);
    }

    /** @param array<int, string> $keys */
    private function deleteSnapshotKeys(
        Connection $connection,
        array $keys,
    ): void {
        try {
            $connection->del(...$keys);
        } catch (Throwable) {
            // Snapshot keys retain a bounded TTL when eager cleanup is unavailable.
        }
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function isReleased(array $payload, float $asOf): bool
    {
        if (isset($payload['retry_of'])) {
            return false;
        }

        if (is_numeric($payload['horizonNewDawn']['madeAvailableAt'] ?? null)) {
            return false;
        }

        $createdAt = $payload['createdAt'] ?? $payload['pushedAt'] ?? null;
        $delay = $payload['delay'] ?? null;

        return is_numeric($createdAt)
            && is_numeric($delay)
            && (float) $delay > 0
            && (float) $createdAt + (float) $delay <= $asOf;
    }
}
