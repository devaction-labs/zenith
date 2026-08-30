<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\Dashboard\DashboardData;
use DevactionLabs\HorizonNewDawn\Dashboard\Data\DashboardSummaryData;
use DevactionLabs\HorizonNewDawn\Dashboard\Data\DashboardSupervisorsData;
use DevactionLabs\HorizonNewDawn\Dashboard\Data\DashboardWorkloadData;
use DevactionLabs\HorizonNewDawn\Support\Data\PageMetaData;
use DevactionLabs\HorizonNewDawn\Support\NavigationItem;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function index(DashboardData $dashboard): Response
    {
        return Inertia::render('Dashboard', [
            'meta' => new PageMetaData('Dashboard', NavigationItem::Dashboard),
            'summary' => fn (): DashboardSummaryData => $dashboard->summary(),
            'workload' => fn (): DashboardWorkloadData => $dashboard->workload(),
            'supervisors' => fn (): DashboardSupervisorsData => $dashboard->supervisors(),
        ]);
    }
}
