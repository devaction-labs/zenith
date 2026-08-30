<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\Metrics\MetricsData;
use DevactionLabs\HorizonNewDawn\Metrics\MetricType;
use DevactionLabs\HorizonNewDawn\Support\Data\PageMetaData;
use DevactionLabs\HorizonNewDawn\Support\NavigationItem;
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
