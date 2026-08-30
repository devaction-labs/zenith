<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\BulkOperations\Jobs;

use DevactionLabs\HorizonNewDawn\Batches\Actions\ClearBatches;
use DevactionLabs\HorizonNewDawn\Batches\BatchClearScope;
use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationJob;

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
        return ['horizon-new-dawn', 'bulk:clear-batches', 'scope:'.$this->scope->value];
    }
}
