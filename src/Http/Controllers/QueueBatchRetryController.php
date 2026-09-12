<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\Zenith\BulkOperations\Jobs\RetryQueueBatchesJob;
use DevactionLabs\Zenith\Http\Requests\RetryFailedJobsRequest;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class QueueBatchRetryController
{
    public function store(
        RetryFailedJobsRequest $request,
        BulkOperationDispatcher $operations,
        DatabaseBatchCapability $batchCapability,
        string $queue,
    ): RedirectResponse {
        abort_unless($batchCapability->attributionSupported(), 404);

        try {
            $operations->dispatch(new RetryQueueBatchesJob($queue));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }

        return back()->with(
            'toast.success',
            "Retrying failed batch jobs from {$queue} was queued.",
        );
    }
}
