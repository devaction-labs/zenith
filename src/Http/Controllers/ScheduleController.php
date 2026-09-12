<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Authorization\HorizonAbility;
use DevactionLabs\Zenith\Authorization\HorizonAbilityAuthorizer;
use DevactionLabs\Zenith\Schedule\ScheduleCatalog;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use Inertia\Inertia;
use Inertia\Response;

final class ScheduleController
{
    public function index(ScheduleCatalog $catalog, HorizonAbilityAuthorizer $abilities): Response
    {
        return Inertia::render('Schedule/Index', [
            'meta' => new PageMetaData('Schedule', NavigationItem::Schedule),
            'events' => $catalog->events(),
            'canRun' => $abilities->allows(HorizonAbility::ManageSchedule),
        ]);
    }
}
