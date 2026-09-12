<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use DevactionLabs\Zenith\Http\Requests\MonitorTagRequest;
use DevactionLabs\Zenith\Monitoring\Actions\MonitorTag;
use DevactionLabs\Zenith\Monitoring\Actions\StopMonitoringTag;
use DevactionLabs\Zenith\Monitoring\MonitoringData;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class MonitoringController
{
    public function index(MonitoringData $monitoring): Response
    {
        $page = $monitoring->index();

        return Inertia::render('Monitoring/Index', [
            'meta' => new PageMetaData('Monitoring', NavigationItem::Monitoring),
            'tags' => [
                'data' => $page->tags,
                'available' => $page->available,
                'message' => $page->message,
            ],
        ]);
    }

    public function store(MonitorTagRequest $request, MonitorTag $monitor): RedirectResponse
    {
        $tag = $request->string('tag')->toString();

        try {
            $monitor->handle($tag);

            return back()->with('toast.success', "Now monitoring {$tag}.");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', "Could not monitor {$tag}.");
        }
    }

    public function destroy(StopMonitoringTag $stop, string $tag): RedirectResponse
    {
        try {
            $stop->handle($tag);

            return back()->with('toast.success', "Stopped monitoring {$tag}.");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', "Could not stop monitoring {$tag}.");
        }
    }
}
