<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches;

use Throwable;

final readonly class DatabaseBatchMetadata
{
    public function __construct(
        public string $batchId,
        public ?string $queue,
        public ?string $connection,
        public bool $queueIsExplicit,
        public bool $connectionIsExplicit,
    ) {}

    public static function fromSerializedOptions(
        string $batchId,
        mixed $serializedOptions,
        bool $postgres,
    ): self {
        $options = self::options($serializedOptions, $postgres);
        $queue = self::explicitString($options, 'queue');
        $connection = self::explicitString($options, 'connection');

        return new self(
            batchId: $batchId,
            queue: $queue,
            connection: $connection,
            queueIsExplicit: $queue !== null,
            connectionIsExplicit: $connection !== null,
        );
    }

    /** @param array<string, mixed> $options */
    public static function fromOptions(string $batchId, array $options): self
    {
        $queue = self::explicitString($options, 'queue');
        $connection = self::explicitString($options, 'connection');

        return new self(
            batchId: $batchId,
            queue: $queue,
            connection: $connection,
            queueIsExplicit: $queue !== null,
            connectionIsExplicit: $connection !== null,
        );
    }

    /** @return array<string, bool|string|null> */
    public function toDatabaseRow(): array
    {
        return [
            'batch_id' => $this->batchId,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'queue_is_explicit' => $this->queueIsExplicit,
            'connection_is_explicit' => $this->connectionIsExplicit,
        ];
    }

    /** @return array<string, mixed> */
    private static function options(mixed $value, bool $postgres): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        if ($postgres && ! str_contains($value, ':') && ! str_contains($value, ';')) {
            $decoded = base64_decode($value, true);

            if ($decoded === false) {
                return [];
            }

            $value = $decoded;
        }

        try {
            $options = unserialize($value, ['allowed_classes' => false]);
        } catch (Throwable) {
            return [];
        }

        return is_array($options) ? $options : [];
    }

    /** @param array<string, mixed> $options */
    private static function explicitString(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
