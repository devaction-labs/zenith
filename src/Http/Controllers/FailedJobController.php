<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\FailedJobs\Actions\RemoveFailedJob;
use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobsData;
use DevactionLabs\HorizonNewDawn\Http\Requests\JobIndexRequest;
use DevactionLabs\HorizonNewDawn\Support\Data\PageMetaData;
use DevactionLabs\HorizonNewDawn\Support\NavigationItem;
use DevactionLabs\HorizonNewDawn\Support\Scrolling\HorizonScrollMetadata;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class FailedJobController
{
    public function index(JobIndexRequest $request, FailedJobsData $jobs): Response
    {
        $query = $request->tag();
        $filters = $request->getData();
        $startingAt = $request->startingAt();
        $resolvePage = fn () => once(
            fn () => $jobs->page(
                $startingAt,
                $query,
                $filters,
            ),
        );

        return Inertia::render('FailedJobs/Index', [
            'meta' => new PageMetaData('Failed Jobs', NavigationItem::Failed),
            'query' => $query ?? '',
            'filters' => $filters,
            'filterCatalog' => Inertia::optional(fn () => $jobs->filters()),
            'querySignature' => fn () => $jobs->querySignature(
                $filters,
                $query,
            ),
            'actions' => fn () => $jobs->bulkActions(),
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

    public function show(FailedJobsData $jobs, string $job): Response
    {
        $detail = $jobs->find($job);

        abort_if($detail === null, 404);

        return Inertia::render('FailedJobs/Show', [
            'meta' => new PageMetaData('Failed Job Detail', NavigationItem::Failed),
            'job' => $detail,
        ]);
    }

    public function destroy(RemoveFailedJob $remove, string $job): RedirectResponse
    {
        try {
            $remove->handle($job);

            return to_route('horizon-new-dawn.failed-jobs.index')
                ->with('toast.success', "Removed failed job {$job}.");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast.error', 'The failed job could not be removed.');
        }
    }
}
