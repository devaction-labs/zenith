<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Dashboard\DashboardData;
use DevactionLabs\Zenith\Dashboard\Data\DashboardSummaryData;
use DevactionLabs\Zenith\Dashboard\Data\DashboardSupervisorsData;
use DevactionLabs\Zenith\Dashboard\Data\DashboardWorkloadData;
use DevactionLabs\Zenith\Queues\Data\QueueBypassWarningData;
use DevactionLabs\Zenith\Queues\QueueBypassWarning;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function index(DashboardData $dashboard, QueueBypassWarning $bypassWarning): Response
    {
        return Inertia::render('Dashboard', [
            'meta' => new PageMetaData('Dashboard', NavigationItem::Dashboard),
            'summary' => fn (): DashboardSummaryData => $dashboard->summary(),
            'workload' => fn (): DashboardWorkloadData => $dashboard->workload(),
            'supervisors' => fn (): DashboardSupervisorsData => $dashboard->supervisors(),
            'queueBypassWarning' => fn (): QueueBypassWarningData => $bypassWarning->summary(),
        ]);
    }
}
