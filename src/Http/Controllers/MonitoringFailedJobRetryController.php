<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\RetryMonitoredFailedJobsJob;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class MonitoringFailedJobRetryController
{
    public function store(
        BulkOperationDispatcher $dispatcher,
        string $tag,
    ): RedirectResponse {
        try {
            $dispatcher->dispatch(new RetryMonitoredFailedJobsJob($tag));

            return back()->with(
                'toast.success',
                "Retrying failed jobs tagged {$tag} was queued.",
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
