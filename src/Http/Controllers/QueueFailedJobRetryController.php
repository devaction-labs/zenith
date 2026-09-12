<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryAllFailedJobsJob;
use DevactionLabs\Zenith\Http\Requests\RetryQueueFailedJobsRequest;
use Illuminate\Http\RedirectResponse;
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
