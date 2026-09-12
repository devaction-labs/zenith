<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Http\Requests\JobIndexRequest;
use DevactionLabs\Zenith\Monitoring\MonitoringData;
use DevactionLabs\Zenith\Monitoring\MonitoringStatus;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use DevactionLabs\Zenith\Support\Scrolling\HorizonScrollMetadata;
use Inertia\Inertia;
use Inertia\Response;

final class MonitoringTagController
{
    public function show(
        JobIndexRequest $request,
        MonitoringData $monitoring,
        string $tag,
        ?string $status = null,
    ): Response {
        $monitoringStatus = MonitoringStatus::from($status ?? MonitoringStatus::Jobs->value);
        $startingAt = $request->startingAt() ?? 0;
        $query = $request->search();
        $filters = $request->getData();
        $resolvePage = fn () => once(
            fn () => $monitoring->page($tag, $monitoringStatus, $startingAt, $filters, $query),
        );
        $title = $monitoringStatus === MonitoringStatus::Failed
            ? "Failed Jobs for \"{$tag}\""
            : "Recent Jobs for \"{$tag}\"";

        return Inertia::render('Monitoring/Show', [
            'meta' => new PageMetaData($title, NavigationItem::Monitoring),
            'tag' => $tag,
            'status' => $monitoringStatus->value,
            'query' => $query ?? '',
            'filters' => $filters,
            'summary' => fn () => $monitoring->summary($tag),
            'listRevision' => function () use ($resolvePage): string {
                $page = $resolvePage();

                return json_encode([
                    $page->total,
                    $page->items[0]->id ?? null,
                ], JSON_THROW_ON_ERROR);
            },
            'jobs' => Inertia::scroll(
                function () use ($resolvePage): array {
                    $page = $resolvePage();

                    return [
                        'data' => $page->items,
                        'total' => $page->total,
                        'available' => $page->available,
                        'message' => $page->message,
                    ];
                },
                'data',
                function (array $_value) use ($resolvePage): HorizonScrollMetadata {
                    $page = $resolvePage();

                    return new HorizonScrollMetadata(
                        'starting_at',
                        null,
                        $page->next,
                        $page->current,
                    );
                },
            )->matchOn('data.id'),
        ]);
    }
}
