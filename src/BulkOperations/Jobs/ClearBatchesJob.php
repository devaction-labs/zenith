<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\BulkOperations\Jobs;

use DevactionLabs\Zenith\Batches\Actions\ClearBatches;
use DevactionLabs\Zenith\Batches\BatchClearScope;
use DevactionLabs\Zenith\BulkOperations\BulkOperationJob;

final class ClearBatchesJob extends BulkOperationJob
{
    public function __construct(public readonly BatchClearScope $scope)
    {
        parent::__construct();
    }

    public function handle(ClearBatches $clear): void
    {
        $this->reportCompletion(
            'clear-batches',
            $clear->handle($this->scope),
            ['scope' => $this->scope->value],
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['zenith', 'bulk:clear-batches', 'scope:'.$this->scope->value];
    }
}
