<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\RetryBatchJob;
use NckRtl\HorizonNewDawn\Http\Requests\RetryFailedJobsRequest;
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
