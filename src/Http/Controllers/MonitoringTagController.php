<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use NckRtl\HorizonNewDawn\Monitoring\MonitoringData;
use NckRtl\HorizonNewDawn\Monitoring\MonitoringStatus;
use NckRtl\HorizonNewDawn\Support\Data\PageMetaData;
use NckRtl\HorizonNewDawn\Support\NavigationItem;
use NckRtl\HorizonNewDawn\Support\Scrolling\HorizonScrollMetadata;

final class MonitoringTagController
{
    public function show(
        Request $request,
        MonitoringData $monitoring,
        string $tag,
        ?string $status = null,
    ): Response {
        $monitoringStatus = MonitoringStatus::from($status ?? MonitoringStatus::Jobs->value);
        $startingAt = $request->integer('starting_at', 0);
        $resolvePage = fn () => once(
            fn () => $monitoring->page($tag, $monitoringStatus, $startingAt),
        );
        $title = $monitoringStatus === MonitoringStatus::Failed
            ? "Failed Jobs for \"{$tag}\""
            : "Recent Jobs for \"{$tag}\"";

        return Inertia::render('Monitoring/Show', [
            'meta' => new PageMetaData($title, NavigationItem::Monitoring),
            'tag' => $tag,
            'status' => $monitoringStatus->value,
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
