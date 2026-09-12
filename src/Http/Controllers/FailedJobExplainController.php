<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\FailedJobs\FailedJobsData;
use DevactionLabs\Zenith\Zenith;
use Illuminate\Http\RedirectResponse;

final class FailedJobExplainController
{
    public function store(FailedJobsData $failedJobs, string $job): RedirectResponse
    {
        $detail = $failedJobs->find($job);

        if ($detail === null) {
            return back()->with('toast.error', 'The failed job could not be found.');
        }

        if (! $detail->canExplainFailure) {
            return back()->with('toast.error', 'No failure explainer is registered.');
        }

        $explanation = Zenith::explainFailure($detail->name, $detail->exception);

        if ($explanation === null) {
            return back()->with('toast.error', 'The failure explainer returned nothing.');
        }

        return back()->with('toast.success', $explanation);
    }
}
