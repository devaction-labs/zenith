<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\ClearRecentJobsJob;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class MonitoringRecentJobController
{
    public function destroy(
        BulkOperationDispatcher $dispatcher,
        string $tag,
    ): RedirectResponse {
        try {
            $dispatcher->dispatch(new ClearRecentJobsJob($tag));

            return back()->with(
                'toast.success',
                "Clearing recent jobs from {$tag} was queued.",
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }
    }
}
