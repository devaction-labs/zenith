<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Workflows\Workflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkflowRetryController
{
    public function store(Request $request, string $workflow): RedirectResponse
    {
        $step = $request->string('step')->toString();

        Workflow::query()->findOrFail($workflow)->retryFrom($step !== '' ? $step : null);

        return back()->with('toast.success', 'Retried the workflow from the failed step.');
    }
}
