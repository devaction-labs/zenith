<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use DevactionLabs\HorizonNewDawn\BulkOperations\Jobs\RetryBatchJob;
use DevactionLabs\HorizonNewDawn\Http\Requests\RetryFailedJobsRequest;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class BatchRetryController
{
    public function store(
        RetryFailedJobsRequest $request,
        BulkOperationDispatcher $operations,
        string $batch,
    ): RedirectResponse {
        try {
            $operations->dispatch(new RetryBatchJob($batch));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }

        return back()->with(
            'toast.success',
            "Retrying failed jobs for batch {$batch} was queued.",
        );
    }
}
