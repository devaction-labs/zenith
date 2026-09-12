<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Http\Requests\SelectedJobIdsRequest;
use DevactionLabs\Zenith\Jobs\Actions\CancelSelectedPendingJobs;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class PendingJobsSelectedCancelController
{
    public function destroy(
        SelectedJobIdsRequest $request,
        CancelSelectedPendingJobs $cancel,
    ): RedirectResponse {
        $ids = $request->ids();

        try {
            $cancel->handle($ids);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }

        $count = count($ids);
        $label = $count === 1 ? 'job' : 'jobs';

        return back()->with('toast.success', "Cancelling {$count} selected {$label} was queued.");
    }
}
