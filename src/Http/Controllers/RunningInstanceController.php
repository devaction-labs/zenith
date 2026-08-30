<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\Dashboard\DashboardData;
use DevactionLabs\HorizonNewDawn\Dashboard\Data\DashboardSupervisorsData;
use DevactionLabs\HorizonNewDawn\Support\Data\PageMetaData;
use DevactionLabs\HorizonNewDawn\Support\NavigationItem;
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
