<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\Supervisors\SupervisorDetails;
use DevactionLabs\HorizonNewDawn\Support\Data\PageMetaData;
use DevactionLabs\HorizonNewDawn\Support\NavigationItem;
use Inertia\Inertia;
use Inertia\Response;

final class SupervisorController
{
    public function show(SupervisorDetails $supervisors, string $supervisor): Response
    {
        $details = $supervisors->find($supervisor);

        abort_if($details->available && $details->supervisor === null, 404);
        $title = $details->supervisor === null ? 'Supervisor' : $details->supervisor->name;

        return Inertia::render('Supervisors/Show', [
            'meta' => new PageMetaData(
                $title,
                NavigationItem::Instances,
            ),
            'supervisorDetails' => $details,
        ]);
    }
}
