<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Supervisors\Data\SupervisorDetailsData;
use DevactionLabs\Zenith\Supervisors\Data\SupervisorScaleBoundsData;
use DevactionLabs\Zenith\Supervisors\SupervisorDetails;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Inertia\Inertia;
use Inertia\Response;

final class SupervisorController
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

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
            'supervisorScaleBounds' => $this->scaleBounds($details->supervisor),
        ]);
    }

    private function scaleBounds(?SupervisorDetailsData $supervisor): SupervisorScaleBoundsData
    {
        if ($supervisor === null) {
            return new SupervisorScaleBoundsData(
                min: $this->configuredBound('min', 1),
                max: $this->configuredBound('max', 20),
            );
        }

        return new SupervisorScaleBoundsData(
            min: $supervisor->minProcesses ?? $this->configuredBound('min', 1),
            max: $supervisor->maxProcesses ?? $this->configuredBound('max', 20),
        );
    }

    private function configuredBound(string $key, int $default): int
    {
        $value = $this->config->get("zenith.supervisor_scale_bounds.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
