<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches;

use DevactionLabs\HorizonNewDawn\Batches\Data\BatchQueryCapabilityData;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class DatabaseBatchCapability
{
    public const string METADATA_TABLE = 'horizon_new_dawn_batch_metadata';

    private const array SUPPORTED_DRIVERS = [
        'mariadb',
        'mysql',
        'pgsql',
        'sqlite',
    ];

    public function __construct(private BatchRepository $repository) {}

    /**
     * Whether batch storage is usable for generic listing and navigation.
     *
     * Non-database repositories remain available even when no relational
     * job_batches table exists. Database repositories require their configured
     * source table.
     */
    public function available(): bool
    {
        if (! $this->repository instanceof DatabaseBatchRepository) {
            return true;
        }

        return match ($this->schemaState()) {
            'supported', 'metadata-missing', 'driver-unsupported' => true,
            default => false,
        };
    }

    public function supported(): bool
    {
        if (! $this->repository instanceof DatabaseBatchRepository) {
            return false;
        }

        return in_array($this->schemaState(), ['supported', 'metadata-missing'], true);
    }

    public function attributionSupported(): bool
    {
        return $this->repository instanceof DatabaseBatchRepository
            && $this->schemaState() === 'supported';
    }

    public function capability(): BatchQueryCapabilityData
    {
        return new BatchQueryCapabilityData(
            supported: $this->supported(),
            message: $this->message(),
            attributionSupported: $this->attributionSupported(),
            attributionMessage: $this->attributionMessage(),
        );
    }

    /**
     * Operator-facing guidance when database batch storage is not available.
     */
    public function storageMessage(): ?string
    {
        if ($this->available()) {
            return null;
        }

        return match ($this->schemaState()) {
            'source-missing' => 'Laravel job batching is not configured. Run php artisan make:queue-batches-table followed by php artisan migrate.',
            default => 'Batches are currently unavailable.',
        };
    }

    public function message(): ?string
    {
        if (! $this->repository instanceof DatabaseBatchRepository) {
            return 'Exact retained batch queries require Laravel\'s database batch repository.';
        }

        return match ($this->schemaState()) {
            'supported', 'metadata-missing' => null,
            'driver-unsupported' => 'The configured batch database driver is not supported for exact filters and sorting.',
            'source-missing' => 'The configured Laravel batch table is unavailable.',
            default => 'The configured batch database could not be inspected.',
        };
    }

    public function attributionMessage(): ?string
    {
        if (! $this->repository instanceof DatabaseBatchRepository) {
            return 'Queue and connection attribution require Laravel\'s database batch repository.';
        }

        return match ($this->schemaState()) {
            'supported' => null,
            'metadata-missing' => 'Run the Horizon New Dawn batch metadata migration to enable queue and connection attribution.',
            'driver-unsupported' => 'The configured batch database driver is not supported for queue and connection attribution.',
            'source-missing' => 'The configured Laravel batch table is unavailable.',
            default => 'The configured batch database could not be inspected.',
        };
    }

    public function connection(): Connection
    {
        if ($this->repository instanceof DatabaseBatchRepository) {
            return $this->repository->getConnection();
        }

        throw new RuntimeException(
            'The configured batch repository does not expose a SQL connection.',
        );
    }

    public function sourceTable(): string
    {
        $table = config('queue.batching.table', 'job_batches');

        return is_string($table) && $table !== '' ? $table : 'job_batches';
    }

    private function schemaState(): string
    {
        return once(function (): string {
            try {
                $connection = $this->connection();
                $schema = $connection->getSchemaBuilder();

                if (! $schema->hasTable($this->sourceTable())) {
                    return 'source-missing';
                }

                if (! in_array(
                    $connection->getDriverName(),
                    self::SUPPORTED_DRIVERS,
                    true,
                )) {
                    return 'driver-unsupported';
                }

                if (! $schema->hasTable(self::METADATA_TABLE)) {
                    return 'metadata-missing';
                }

                return 'supported';
            } catch (Throwable) {
                return 'unavailable';
            }
        });
    }
}
