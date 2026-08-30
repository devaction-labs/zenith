<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\Batches\DatabaseBatchCapability;
use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\RetryQueueBatchesJob;
use DevactionLabs\HorizonNewDawn\Http\Requests\RetryFailedJobsRequest;
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
