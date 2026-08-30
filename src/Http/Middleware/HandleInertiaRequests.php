<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Middleware;

use DevactionLabs\HorizonNewDawn\Assets\AssetManifest;
use DevactionLabs\HorizonNewDawn\Authorization\HorizonAbilityAuthorizer;
use DevactionLabs\HorizonNewDawn\Monitoring\MonitoringData;
use DevactionLabs\HorizonNewDawn\Queues\QueuePauseStatus;
use DevactionLabs\HorizonNewDawn\Support\Data\HorizonShellData;
use DevactionLabs\HorizonNewDawn\Support\Data\NavigationCountsData;
use DevactionLabs\HorizonNewDawn\Support\FrameworkCapabilities;
use DevactionLabs\HorizonNewDawn\Support\HorizonRuntime;
use DevactionLabs\HorizonNewDawn\Support\NavigationCounts;
use DevactionLabs\HorizonNewDawn\Support\PollInterval;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'horizon-new-dawn::app';

    public function __construct(
        private readonly HorizonRuntime $runtime,
        private readonly NavigationCounts $navigationCounts,
        private readonly MonitoringData $monitoring,
        private readonly Application $application,
        private readonly AssetManifest $assets,
        private readonly FrameworkCapabilities $capabilities,
        private readonly QueuePauseStatus $queuePauseStatus,
        private readonly HorizonAbilityAuthorizer $abilities,
    ) {}

    public function version(Request $request): string
    {
        return $this->assets->version();
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => [
                'success' => fn (): ?string => $this->flashMessage($request, 'toast.success'),
                'error' => fn (): ?string => $this->flashMessage($request, 'toast.error'),
            ],
            'horizon' => function (): HorizonShellData {
                $status = $this->runtime->status();

                return new HorizonShellData(
                    baseUrl: route('horizon-new-dawn.dashboard'),
                    pollInterval: PollInterval::milliseconds(),
                    status: $status,
                    processing: $this->runtime->isProcessing($status),
                    maintenanceMode: $this->application->isDownForMaintenance(),
                    capabilities: $this->capabilities,
                    jobNavigationBreakdown: config(
                        'horizon-new-dawn.job_navigation_breakdown',
                        false,
                    ) === true,
                    allQueuesPaused: $this->queuePauseStatus->allPaused(),
                    abilities: $this->abilities->abilities(),
                );
            },
            'monitoredTags' => fn (): array => $this->monitoring->monitoredTags(),
            'navigationCounts' => Inertia::defer(
                fn (): NavigationCountsData => $this->navigationCounts->get(),
                'navigation',
            ),
        ];
    }

    private function flashMessage(Request $request, string $key): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $message = $request->session()->get($key);

        return is_string($message) ? $message : null;
    }
}
