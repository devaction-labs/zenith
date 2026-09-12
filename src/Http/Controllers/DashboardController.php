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
use DevactionLabs\Zenith\Telemetry\Data\ThroughputChartData;
use DevactionLabs\Zenith\Telemetry\TelemetryGroupBy;
use DevactionLabs\Zenith\Telemetry\TelemetryMetricsReader;
use DevactionLabs\Zenith\Telemetry\TelemetryWindow;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __construct(private readonly TelemetryMetricsReader $telemetryMetrics) {}

    public function index(DashboardData $dashboard, Request $request, QueueBypassWarning $bypassWarning): Response
    {
        $groupBy = $this->groupByFromRequest($request);
        $window = $this->windowFromRequest($request);

        return Inertia::render('Dashboard', [
            'meta' => new PageMetaData('Dashboard', NavigationItem::Dashboard),
            'summary' => fn (): DashboardSummaryData => $dashboard->summary(),
            'workload' => fn (): DashboardWorkloadData => $dashboard->workload(),
            'supervisors' => fn (): DashboardSupervisorsData => $dashboard->supervisors(),
            'liveThroughput' => fn (): ThroughputChartData => $this->telemetryMetrics->throughput($window, $groupBy),
            'liveMetricsGroupBy' => $groupBy->value,
            'liveMetricsWindow' => $window->value,
            'queueBypassWarning' => fn (): QueueBypassWarningData => $bypassWarning->summary(),
        ]);
    }

    private function groupByFromRequest(Request $request): TelemetryGroupBy
    {
        $value = $request->query('groupBy');

        return (is_string($value) ? TelemetryGroupBy::tryFrom($value) : null) ?? TelemetryGroupBy::State;
    }

    private function windowFromRequest(Request $request): TelemetryWindow
    {
        $value = $request->query('window');

        return (is_string($value) ? TelemetryWindow::tryFrom($value) : null) ?? TelemetryWindow::OneHour;
    }
}
