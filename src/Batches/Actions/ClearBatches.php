<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches\Actions;

use DevactionLabs\Zenith\Batches\BatchClearScope;
use DevactionLabs\Zenith\Batches\ClearableBatches;
use Illuminate\Bus\BatchRepository;

/**
 * Deletes clearable batches in bounded repository transactions streamed from
 * ClearableBatches so the worker never holds an unbounded ID list.
 */
final readonly class ClearBatches
{
    private const int DELETE_CHUNK_SIZE = 100;

    public function __construct(
        private BatchRepository $batches,
        private ClearableBatches $clearable,
    ) {}

    public function handle(BatchClearScope $scope): int
    {
        $cleared = 0;

        foreach ($this->clearable->idChunks($scope, self::DELETE_CHUNK_SIZE) as $chunk) {
            $this->batches->transaction(function () use ($chunk): void {
                foreach ($chunk as $id) {
                    $this->batches->delete($id);
                }
            });

            $cleared += count($chunk);
        }

        return $cleared;
    }
}
