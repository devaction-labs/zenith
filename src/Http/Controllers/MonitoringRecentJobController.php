<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\ClearRecentJobsJob;
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
