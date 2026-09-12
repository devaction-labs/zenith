<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Dashboard\DashboardData;
use DevactionLabs\Zenith\Dashboard\Data\DashboardSupervisorsData;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use Inertia\Inertia;
use Inertia\Response;

final class RunningInstanceController
{
    public function index(DashboardData $dashboard): Response
    {
        return Inertia::render('Instances/Index', [
            'meta' => new PageMetaData('Instances', NavigationItem::Instances),
            'supervisors' => fn (): DashboardSupervisorsData => $dashboard->supervisors(),
        ]);
    }
}
