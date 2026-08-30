<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\RetryAllFailedJobsJob;
use DevactionLabs\HorizonNewDawn\Http\Requests\RetryFailedJobsRequest;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class FailedJobRetryAllController
{
    public function store(
        RetryFailedJobsRequest $request,
        BulkOperationDispatcher $dispatcher,
    ): RedirectResponse {
        try {
            $dispatcher->dispatch(new RetryAllFailedJobsJob);

            return back()->with('toast.success', 'Retrying all failed jobs was queued.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }
    }
}
