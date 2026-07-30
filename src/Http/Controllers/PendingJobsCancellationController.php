<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationDispatcher;
use NckRtl\HorizonNewDawn\BulkOperations\Jobs\CancelPendingJobsJob;
use NckRtl\HorizonNewDawn\Jobs\PendingJobCancellationScope;
use Throwable;

final class PendingJobsCancellationController
{
    public function destroy(
        BulkOperationDispatcher $dispatcher,
        PendingJobCancellationScope $scope,
        Request $request,
    ): RedirectResponse {
        $queue = $request->query('queue');
        $queue = is_string($queue) && $queue !== '' ? $queue : null;

        try {
            $dispatcher->dispatch(new CancelPendingJobsJob($scope, $queue));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }

        $label = $scope === PendingJobCancellationScope::Pending
            ? 'pending'
            : $scope->value;
        $message = "Cancelling {$label} jobs";

        if ($queue !== null) {
            $message .= " from {$queue}";
        }

        return back()->with('toast.success', "{$message} was queued.");
    }
}
