<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use DevactionLabs\Zenith\Telemetry\Data\RunningJobsPageData;
use DevactionLabs\Zenith\Telemetry\InFlightJobs;
use Inertia\Inertia;
use Inertia\Response;

final class ExecutingJobController
{
    public function index(InFlightJobs $jobs): Response
    {
        return Inertia::render('Executing/Index', [
            'meta' => new PageMetaData('Executing Now', NavigationItem::Executing),
            'executing' => fn (): RunningJobsPageData => $jobs->list(),
        ]);
    }
}
