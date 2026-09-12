<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Metrics\MetricsData;
use DevactionLabs\Zenith\Metrics\MetricType;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use Inertia\Inertia;
use Inertia\Response;

final class MetricController
{
    public function show(MetricsData $metrics, string $type, string $slug): Response
    {
        $metricType = MetricType::from($type);
        $preview = $metrics->preview($metricType, $slug);

        return Inertia::render('Metrics/Show', [
            'meta' => new PageMetaData("Metrics for {$slug}", NavigationItem::Metrics),
            'type' => $metricType->value,
            'name' => $slug,
            'preview' => [
                'data' => $preview->snapshots,
                'available' => $preview->available,
                'message' => $preview->message,
            ],
        ]);
    }
}
