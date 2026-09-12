<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Workflows\Workflow;
use Illuminate\Http\RedirectResponse;

final class WorkflowCancelController
{
    public function store(string $workflow): RedirectResponse
    {
        $model = Workflow::query()->findOrFail($workflow);
        $model->cancel();

        return back()->with('toast.success', 'Cancelled the workflow.');
    }
}
