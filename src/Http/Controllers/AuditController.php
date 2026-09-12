<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Audit\HorizonAuditLog;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use DevactionLabs\Zenith\Support\Scrolling\HorizonScrollMetadata;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AuditController
{
    public function index(Request $request, HorizonAuditLog $audit): Response
    {
        $startingAt = $request->integer('starting_at', 0);
        $resolvePage = fn () => once(
            fn () => $audit->page($startingAt > 0 ? $startingAt : null),
        );

        return Inertia::render('Audit/Index', [
            'meta' => new PageMetaData('Audit', NavigationItem::Audit),
            'events' => Inertia::scroll(
                function () use ($resolvePage): array {
                    $page = $resolvePage();

                    return [
                        'data' => $page->events,
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
                        $page->events === [] ? 0 : $page->events[array_key_last($page->events)]->id,
                    );
                },
            )->matchOn('data.id'),
        ]);
    }
}
