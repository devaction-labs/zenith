<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Middleware;

use DevactionLabs\Zenith\Assets\AssetManifest;
use DevactionLabs\Zenith\Authorization\HorizonAbilityAuthorizer;
use DevactionLabs\Zenith\Monitoring\MonitoringData;
use DevactionLabs\Zenith\Queues\QueuePauseStatus;
use DevactionLabs\Zenith\Support\Data\HorizonShellData;
use DevactionLabs\Zenith\Support\Data\NavigationCountsData;
use DevactionLabs\Zenith\Support\FrameworkCapabilities;
use DevactionLabs\Zenith\Support\HorizonRuntime;
use DevactionLabs\Zenith\Support\NavigationCounts;
use DevactionLabs\Zenith\Support\PollInterval;
use DevactionLabs\Zenith\Telemetry\TelemetryRegistration;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'zenith::app';

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
                    baseUrl: route('zenith.dashboard'),
                    pollInterval: PollInterval::milliseconds(),
                    status: $status,
                    processing: $this->runtime->isProcessing($status),
                    maintenanceMode: $this->application->isDownForMaintenance(),
                    capabilities: $this->capabilities,
                    jobNavigationBreakdown: config(
                        'zenith.job_navigation_breakdown',
                        false,
                    ) === true,
                    allQueuesPaused: $this->queuePauseStatus->allPaused(),
                    abilities: $this->abilities->abilities(),
                    telemetryEnabled: TelemetryRegistration::enabled(),
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
