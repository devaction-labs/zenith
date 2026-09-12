<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\FailedJobs\Actions\RetryFailedJob;
use DevactionLabs\Zenith\Http\Requests\RetryFailedJobsRequest;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class FailedJobRetryController
{
    public function store(
        RetryFailedJobsRequest $request,
        RetryFailedJob $retry,
        string $job,
    ): RedirectResponse {
        try {
            if (! $retry->handle($job)) {
                return back()->with(
                    'toast.error',
                    "No retry was scheduled because {$job} is no longer eligible.",
                );
            }

            return back()->with('toast.success', "Retry scheduled for {$job}.");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', 'The failed job could not be retried.');
        }
    }
}
