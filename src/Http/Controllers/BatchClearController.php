<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use NckRtl\HorizonNewDawn\Batches\BatchClearScope;
use NckRtl\HorizonNewDawn\Batches\ClearableBatches;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\ClearBatchesJob;
use Throwable;

final class BatchClearController
{
    public function destroy(
        BulkOperationDispatcher $operations,
        ClearableBatches $clearable,
        BatchClearScope $scope,
    ): RedirectResponse {
        try {
            $counts = $clearable->counts();

            if (! $counts->available || ! $counts->completeScan) {
                return back()->with(
                    'toast.error',
                    $counts->message ?? 'Batch clearing availability could not be verified.',
                );
            }

            $operations->dispatch(new ClearBatchesJob($scope));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }

        return back()->with(
            'toast.success',
            "Clearing {$scope->value} batches was queued.",
        );
    }
}
