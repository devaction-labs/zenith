<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Metrics\MetricsData;
use DevactionLabs\Zenith\Metrics\MetricType;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use DevactionLabs\Zenith\Telemetry\Data\PercentileChartData;
use DevactionLabs\Zenith\Telemetry\TelemetryDimension;
use DevactionLabs\Zenith\Telemetry\TelemetryMetric;
use DevactionLabs\Zenith\Telemetry\TelemetryMetricsReader;
use DevactionLabs\Zenith\Telemetry\TelemetryWindow;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MetricController
{
    public function __construct(private readonly TelemetryMetricsReader $telemetryMetrics) {}

    public function show(MetricsData $metrics, Request $request, string $type, string $slug): Response
    {
        $metricType = MetricType::from($type);
        $preview = $metrics->preview($metricType, $slug);
        $window = $this->windowFromRequest($request);
        $dimension = match ($metricType) {
            MetricType::Jobs => TelemetryDimension::JobClass,
            MetricType::Queues => TelemetryDimension::Queue,
        };

        return Inertia::render('Metrics/Show', [
            'meta' => new PageMetaData("Metrics for {$slug}", NavigationItem::Metrics),
            'type' => $metricType->value,
            'name' => $slug,
            'preview' => [
                'data' => $preview->snapshots,
                'available' => $preview->available,
                'message' => $preview->message,
            ],
            'percentiles' => fn (): PercentileChartData => $this->telemetryMetrics->percentiles(
                $window,
                TelemetryMetric::Runtime,
                $dimension,
                $slug,
            ),
            'percentilesWindow' => $window->value,
        ]);
    }

    private function windowFromRequest(Request $request): TelemetryWindow
    {
        $value = $request->query('window');

        return (is_string($value) ? TelemetryWindow::tryFrom($value) : null) ?? TelemetryWindow::OneHour;
    }
}
