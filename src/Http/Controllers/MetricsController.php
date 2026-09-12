<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Metrics\MetricsData;
use DevactionLabs\Zenith\Metrics\MetricType;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class MetricsController
{
    public function redirect(): RedirectResponse
    {
        return redirect()->route('zenith.metrics.index', [
            'type' => MetricType::Jobs->value,
        ]);
    }

    public function index(MetricsData $metrics, string $type): Response
    {
        $metricType = MetricType::from($type);
        $page = $metrics->index($metricType);

        return Inertia::render('Metrics/Index', [
            'meta' => new PageMetaData('Metrics', NavigationItem::Metrics),
            'type' => $metricType->value,
            'metrics' => [
                'data' => $page->metrics,
                'available' => $page->available,
                'message' => $page->message,
            ],
        ]);
    }
}
