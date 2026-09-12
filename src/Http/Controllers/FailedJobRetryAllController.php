<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryAllFailedJobsJob;
use DevactionLabs\Zenith\Http\Requests\RetryFailedJobsRequest;
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
