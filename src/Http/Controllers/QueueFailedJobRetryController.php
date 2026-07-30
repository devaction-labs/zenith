<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\RetryAllFailedJobsJob;
use NckRtl\HorizonNewDawn\Http\Requests\RetryQueueFailedJobsRequest;
use Throwable;

final class QueueFailedJobRetryController
{
    public function store(
        RetryQueueFailedJobsRequest $request,
        BulkOperationDispatcher $dispatcher,
    ): RedirectResponse {
        $data = $request->getData();

        try {
            $dispatcher->dispatch(new RetryAllFailedJobsJob($data->connection, $data->queue));

            return back()->with(
                'toast.success',
                "Retrying failed jobs from {$data->queue} was queued.",
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
