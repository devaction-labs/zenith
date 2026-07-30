<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\BulkOperations\Jobs;

use NckRtl\HorizonNewDawn\Batches\Actions\ClearBatches;
use NckRtl\HorizonNewDawn\Batches\BatchClearScope;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationJob;

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
