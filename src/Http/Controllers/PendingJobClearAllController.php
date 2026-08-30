<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\ClearPendingJobsJob;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class PendingJobClearAllController
{
    public function destroy(
        BulkOperationDispatcher $dispatcher,
    ): RedirectResponse {
        try {
            $dispatcher->dispatch(new ClearPendingJobsJob);

            return back()->with('toast.success', 'Clearing all pending jobs was queued.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }
    }
}
