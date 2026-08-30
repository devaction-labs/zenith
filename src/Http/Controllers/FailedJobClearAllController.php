<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\ClearFailedJobsJob;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class FailedJobClearAllController
{
    public function destroy(
        BulkOperationDispatcher $dispatcher,
    ): RedirectResponse {
        try {
            $dispatcher->dispatch(new ClearFailedJobsJob);

            return back()->with('toast.success', 'Clearing all failed jobs was queued.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }
    }
}
