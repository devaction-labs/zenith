<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Jobs\Actions\RetryRetainedJob;
use DevactionLabs\Zenith\Jobs\RetainedJobRetryResult;
use Illuminate\Http\RedirectResponse;

final class JobRetryController
{
    public function store(RetryRetainedJob $retry, string $type, string $job): RedirectResponse
    {
        return match ($retry->handle($job)) {
            RetainedJobRetryResult::Retried => back()->with(
                'toast.success',
                "Retry scheduled for {$job}.",
            ),
            RetainedJobRetryResult::NotRetained => back()->with(
                'toast.error',
                "No retry was scheduled because {$job} is no longer retained.",
            ),
            RetainedJobRetryResult::ClassMissing => back()->with(
                'toast.error',
                "No retry was scheduled because {$job}'s job class no longer exists.",
            ),
            RetainedJobRetryResult::UniqueOrDebounced => back()->with(
                'toast.error',
                "No retry was scheduled because {$job} enforces uniqueness or debouncing and must be re-dispatched by the application instead.",
            ),
        };
    }
}
