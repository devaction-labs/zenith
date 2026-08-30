<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Controllers;

use DevactionLabs\HorizonNewDawn\Http\Requests\JobIndexRequest;
use DevactionLabs\HorizonNewDawn\Jobs\JobListType;
use DevactionLabs\HorizonNewDawn\Jobs\JobsData;
use DevactionLabs\HorizonNewDawn\Queues\QueuesData;
use DevactionLabs\HorizonNewDawn\Support\Data\PageMetaData;
use DevactionLabs\HorizonNewDawn\Support\Scrolling\HorizonScrollMetadata;
use Inertia\Inertia;
use Inertia\Response;

final class JobController
{
    public function index(
        JobIndexRequest $request,
        JobsData $jobs,
        QueuesData $queues,
        string $type,
    ): Response {
        $jobType = JobListType::from($type);
        $query = $request->search();
        $filters = $request->getData();
        $startingAt = $request->startingAt();
        $resolvePage = fn () => once(
            fn () => $jobs->page(
                $jobType,
                $startingAt,
                $filters,
                $query,
            ),
        );

        return Inertia::render('Jobs/Index', [
            'meta' => new PageMetaData($jobType->title(), $jobType->navigation()),
            'type' => $jobType->value,
            'query' => $query ?? '',
            'filters' => $filters,
            'filterCatalog' => Inertia::optional(fn () => $jobs->filters($jobType)),
            'querySignature' => fn () => $jobs->querySignature(
                $jobType,
                $filters,
                $query,
            ),
            'pendingCounts' => fn () => $jobType === JobListType::Pending
                ? $queues->all()->pendingCounts()
                : null,
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

    public function show(JobsData $jobs, string $type, string $job): Response
    {
        $jobType = JobListType::from($type);
        $detail = $jobs->find($job);

        abort_if($detail === null, 404);

        return Inertia::render('Jobs/Show', [
            'meta' => new PageMetaData('Job Detail', $jobType->navigation()),
            'type' => $jobType->value,
            'job' => $detail,
        ]);
    }
}
