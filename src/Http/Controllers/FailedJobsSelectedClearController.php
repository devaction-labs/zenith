<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\FailedJobs\Actions\ClearSelectedFailedJobs;
use DevactionLabs\Zenith\Http\Requests\SelectedJobIdsRequest;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class FailedJobsSelectedClearController
{
    public function destroy(
        SelectedJobIdsRequest $request,
        ClearSelectedFailedJobs $clear,
    ): RedirectResponse {
        $ids = $request->ids();

        try {
            $clear->handle($ids);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }

        $count = count($ids);
        $label = $count === 1 ? 'job' : 'jobs';

        return back()->with('toast.success', "Removing {$count} selected failed {$label} was queued.");
    }
}
