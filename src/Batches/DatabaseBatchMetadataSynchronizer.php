<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\PostgresConnection;
use RuntimeException;

final class DatabaseBatchMetadataSynchronizer
{
    private const int CHUNK_SIZE = 500;

    private bool $synchronized = false;

    private bool $defaultConnectionResolved = false;

    private ?string $defaultConnection = null;

    /** @var array<string, ?string> */
    private array $configuredQueues = [];

    public function __construct(
        private readonly DatabaseBatchCapability $capability,
        private readonly ConfigRepository $config,
    ) {}

    public function sync(): int
    {
        if ($this->synchronized) {
            return 0;
        }

        if (! $this->capability->attributionSupported()) {
            $this->synchronized = true;

            return 0;
        }

        $connection = $this->capability->connection();
        $sourceTable = $this->capability->sourceTable();
        $lastId = null;
        $inserted = 0;

        do {
            $rows = $connection->table("{$sourceTable} as source")
                ->leftJoin(
                    DatabaseBatchCapability::METADATA_TABLE.' as metadata',
                    'metadata.batch_id',
                    '=',
                    'source.id',
                )
                ->whereNull('metadata.batch_id')
                ->when(
                    $lastId !== null,
                    static fn ($query) => $query->where('source.id', '>', $lastId),
                )
                ->orderBy('source.id')
                ->limit(self::CHUNK_SIZE)
                ->get(['source.id', 'source.options']);

            if ($rows->isEmpty()) {
                break;
            }

            $payload = $rows
                ->map(fn (object $row): array => $this->resolvedMetadata(
                    DatabaseBatchMetadata::fromSerializedOptions(
                        batchId: $this->stringValue($row->id),
                        serializedOptions: $row->options,
                        postgres: $connection instanceof PostgresConnection,
                    ),
                )->toDatabaseRow())
                ->all();

            $inserted += $connection
                ->table(DatabaseBatchCapability::METADATA_TABLE)
                ->insertOrIgnore($payload);

            $last = $rows->last();

            if (! isset($last->id)) {
                throw new RuntimeException('The batch metadata synchronizer could not advance.');
            }

            $nextId = $this->stringValue($last->id);

            if ($lastId !== null && strcmp($nextId, $lastId) <= 0) {
                throw new RuntimeException('The batch metadata synchronizer did not advance.');
            }

            $lastId = $nextId;
        } while ($rows->count() === self::CHUNK_SIZE);

        $metadataCount = $connection
            ->table(DatabaseBatchCapability::METADATA_TABLE)
            ->count();
        $sourceCount = $connection->table($sourceTable)->count();

        if ($metadataCount > $sourceCount) {
            $this->removeStaleMetadata();
        }

        $this->synchronized = true;

        return $inserted;
    }

    public function syncBatch(string $batchId): ?DatabaseBatchMetadata
    {
        if (! $this->capability->attributionSupported()) {
            return null;
        }

        $connection = $this->capability->connection();
        $source = $connection
            ->table($this->capability->sourceTable())
            ->where('id', $batchId)
            ->first(['id', 'options']);

        if (! is_object($source)) {
            return null;
        }

        $metadata = $this->resolvedMetadata(
            DatabaseBatchMetadata::fromSerializedOptions(
                batchId: $this->stringValue($source->id),
                serializedOptions: $source->options,
                postgres: $connection instanceof PostgresConnection,
            ),
        );

        $connection
            ->table(DatabaseBatchCapability::METADATA_TABLE)
            ->insertOrIgnore([$metadata->toDatabaseRow()]);
        $stored = $connection
            ->table(DatabaseBatchCapability::METADATA_TABLE)
            ->where('batch_id', $batchId)
            ->first();

        if (! is_object($stored)) {
            return null;
        }

        return new DatabaseBatchMetadata(
            batchId: $this->stringValue($stored->batch_id),
            queue: $this->nullableString($stored->queue ?? null),
            connection: $this->nullableString($stored->connection ?? null),
            queueIsExplicit: (bool) $stored->queue_is_explicit,
            connectionIsExplicit: (bool) $stored->connection_is_explicit,
        );
    }

    private function removeStaleMetadata(): void
    {
        $connection = $this->capability->connection();
        $sourceTable = $this->capability->sourceTable();
        $metadataTable = DatabaseBatchCapability::METADATA_TABLE;

        do {
            $staleIds = $connection->table("{$metadataTable} as metadata")
                ->leftJoin(
                    "{$sourceTable} as source",
                    'source.id',
                    '=',
                    'metadata.batch_id',
                )
                ->whereNull('source.id')
                ->orderBy('metadata.batch_id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('metadata.batch_id')
                ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
                ->values()
                ->all();

            if ($staleIds === []) {
                return;
            }

            $connection->table($metadataTable)
                ->whereIn('batch_id', $staleIds)
                ->delete();
        } while (count($staleIds) === self::CHUNK_SIZE);
    }

    private function resolvedMetadata(
        DatabaseBatchMetadata $metadata,
    ): DatabaseBatchMetadata {
        $connection = $metadata->connection ?? $this->configuredDefaultConnection();
        $queue = $metadata->queue ?? $this->configuredQueue($connection) ?? 'default';

        return new DatabaseBatchMetadata(
            batchId: $metadata->batchId,
            queue: $queue,
            connection: $connection,
            queueIsExplicit: $metadata->queueIsExplicit,
            connectionIsExplicit: $metadata->connectionIsExplicit,
        );
    }

    private function configuredDefaultConnection(): ?string
    {
        if (! $this->defaultConnectionResolved) {
            $this->defaultConnection = $this->nullableString(
                $this->config->get('queue.default'),
            );
            $this->defaultConnectionResolved = true;
        }

        return $this->defaultConnection;
    }

    private function configuredQueue(?string $connection): ?string
    {
        if ($connection === null) {
            return null;
        }

        if (! array_key_exists($connection, $this->configuredQueues)) {
            $this->configuredQueues[$connection] = $this->nullableString(
                $this->config->get("queue.connections.{$connection}.queue"),
            );
        }

        return $this->configuredQueues[$connection];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
