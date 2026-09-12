<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\BulkOperations;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

abstract class BulkOperationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $connection = config('zenith.bulk_operations.connection');
        $queue = config('zenith.bulk_operations.queue');

        if ($connection !== null) {
            if (! is_string($connection) || trim($connection) === '') {
                throw new InvalidArgumentException(
                    'The zenith.bulk_operations.connection configuration value must be null or a non-empty string.',
                );
            }

            $this->onConnection($connection);
        }

        if ($queue !== null) {
            if (! is_string($queue) || trim($queue) === '') {
                throw new InvalidArgumentException(
                    'The zenith.bulk_operations.queue configuration value must be null or a non-empty string.',
                );
            }

            $this->onQueue($queue);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Zenith bulk operation failed.', [
            'job' => static::class,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'exception' => $exception,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function reportCompletion(string $operation, int $affected, array $context = []): void
    {
        Log::info('Zenith bulk operation completed.', [
            'operation' => $operation,
            'affected' => $affected,
            ...$context,
        ]);
    }

    /**
     * Complete the operation or enqueue the next bounded continuation on the
     * configured bulk-operation connection and queue.
     *
     * @param  array<string, mixed>  $context
     */
    protected function continueOrComplete(
        BulkOperationChunkResult $result,
        string $operation,
        BulkOperationJob $continuation,
        array $context = [],
    ): void {
        if ($result->complete) {
            $this->reportCompletion($operation, $result->totalAffected, $context);

            return;
        }

        Bus::dispatch($continuation);
    }
}
