<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\FailedJobs\Actions\RetrySelectedFailedJobs;
use DevactionLabs\Zenith\Http\Requests\SelectedJobIdsRequest;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class FailedJobsSelectedRetryController
{
    public function store(
        SelectedJobIdsRequest $request,
        RetrySelectedFailedJobs $retry,
    ): RedirectResponse {
        $ids = $request->ids();

        try {
            $retry->handle($ids);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'toast.error',
                'The bulk operation could not be queued. Check the application logs and try again.',
            );
        }

        $count = count($ids);
        $label = $count === 1 ? 'job' : 'jobs';

        return back()->with('toast.success', "Retrying {$count} selected failed {$label} was queued.");
    }
}
