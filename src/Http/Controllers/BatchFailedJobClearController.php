<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\ClearBatchFailedJobsJob;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class BatchFailedJobClearController
{
    public function destroy(
        BulkOperationDispatcher $operations,
        string $batch,
    ): RedirectResponse {
        try {
            $operations->dispatch(new ClearBatchFailedJobsJob($batch));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }

        return back()->with(
            'toast.success',
            "Clearing failed jobs for batch {$batch} was queued.",
        );
    }
}
