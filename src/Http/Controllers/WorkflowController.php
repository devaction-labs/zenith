<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use DevactionLabs\Zenith\Workflows\WorkflowsData;
use Inertia\Inertia;
use Inertia\Response;

final class WorkflowController
{
    public function index(WorkflowsData $workflows): Response
    {
        return Inertia::render('Workflows/Index', [
            'meta' => new PageMetaData('Workflows', NavigationItem::Workflows),
            'available' => $workflows->available(),
            'workflows' => $workflows->list(),
        ]);
    }

    public function show(WorkflowsData $workflows, string $workflow): Response
    {
        $detail = $workflows->find($workflow);

        abort_if($detail === null, 404);

        return Inertia::render('Workflows/Show', [
            'meta' => new PageMetaData($detail->name ?? 'Workflow', NavigationItem::Workflows),
            'workflow' => $detail,
        ]);
    }
}
