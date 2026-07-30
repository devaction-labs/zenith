<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use NckRtl\HorizonNewDawn\Batches\BatchesData;
use NckRtl\HorizonNewDawn\Batches\BatchFilterCatalog;
use NckRtl\HorizonNewDawn\Batches\ClearableBatches;
use NckRtl\HorizonNewDawn\Batches\Data\BatchIndexFiltersData;
use NckRtl\HorizonNewDawn\Batches\DatabaseBatchCapability;
use NckRtl\HorizonNewDawn\Http\Requests\BatchIndexRequest;
use NckRtl\HorizonNewDawn\Support\Data\PageMetaData;
use NckRtl\HorizonNewDawn\Support\NavigationItem;
use NckRtl\HorizonNewDawn\Support\Scrolling\HorizonScrollMetadata;

final class BatchController
{
    public function index(
        BatchIndexRequest $request,
        BatchesData $batches,
        BatchFilterCatalog $batchFilterCatalog,
        ClearableBatches $clearableBatches,
        DatabaseBatchCapability $databaseBatchCapability,
    ): Response {
        if (! $databaseBatchCapability->available()) {
            return Inertia::render('Batches/Index', [
                'meta' => new PageMetaData('Batches', NavigationItem::Batches),
                'batchesAvailable' => false,
                'query' => '',
                'filters' => [
                    'queue' => null,
                    'connection' => null,
                    'created' => null,
                    'status' => 'all',
                    'sort' => 'createdAt',
                    'direction' => 'desc',
                ],
                'batchStatusCounts' => [
                    'all' => 0,
                    'pending' => 0,
                    'finished' => 0,
                    'failures' => 0,
                    'cancelled' => 0,
                ],
                'batchQueryCapability' => $databaseBatchCapability->capability(),
                'batchClearCounts' => [
                    'incomplete' => 0,
                    'complete' => 0,
                    'finished' => 0,
                    'cancelled' => 0,
                    'available' => false,
                    'completeScan' => false,
                    'message' => $databaseBatchCapability->storageMessage(),
                ],
                'batchFilterCatalog' => [
                    'available' => false,
                    'complete' => false,
                    'message' => $databaseBatchCapability->storageMessage(),
                    'queues' => [],
                    'connections' => [],
                ],
                'listRevision' => '[]',
                'batches' => [
                    'data' => [],
                    'available' => false,
                    'complete' => true,
                    'message' => $databaseBatchCapability->storageMessage(),
                ],
            ]);
        }

        $filters = $request->getData();

        if (! $databaseBatchCapability->supported()) {
            $filters = new BatchIndexFiltersData(
                query: null,
                queue: null,
                connection: null,
                created: null,
            );
        } elseif (! $databaseBatchCapability->attributionSupported()) {
            $filters = new BatchIndexFiltersData(
                query: $filters->query,
                queue: null,
                connection: null,
                created: $filters->created,
                status: $filters->status,
                sort: $filters->sort,
                direction: $filters->direction,
            );
        }

        $beforeId = $request->beforeId();
        $resolvePage = fn () => once(
            fn () => $batches->page(
                $beforeId,
                $filters->query,
                $filters->queue,
                $filters->connection,
                $filters->created,
                $filters->status,
                $filters->sort,
                $filters->direction,
            ),
        );
        $resolveStatusCounts = fn () => once(
            fn () => $batches->statusCounts($filters),
        );

        return Inertia::render('Batches/Index', [
            'meta' => new PageMetaData('Batches', NavigationItem::Batches),
            'batchesAvailable' => true,
            'query' => $filters->query ?? '',
            'filters' => [
                'queue' => $filters->queue,
                'connection' => $filters->connection,
                'created' => $filters->created?->value,
                'status' => $filters->status === null ? 'all' : $filters->status->value,
                'sort' => $filters->sort->value,
                'direction' => $filters->direction->value,
            ],
            'batchStatusCounts' => $resolveStatusCounts,
            'batchQueryCapability' => $databaseBatchCapability->capability(...),
            'batchClearCounts' => $clearableBatches->counts(...),
            'batchFilterCatalog' => $batchFilterCatalog->get(...),
            'listRevision' => function () use ($resolvePage, $resolveStatusCounts): string {
                $page = $resolvePage();

                return json_encode([
                    $resolveStatusCounts()->all,
                    $page->batches[0]->id ?? null,
                ], JSON_THROW_ON_ERROR);
            },
            'batches' => Inertia::scroll(
                function () use ($resolvePage): array {
                    $page = $resolvePage();

                    return [
                        'data' => $page->batches,
                        'available' => $page->available,
                        'complete' => $page->complete,
                        'message' => $page->message,
                    ];
                },
                'data',
                function (array $_value) use ($resolvePage): HorizonScrollMetadata {
                    $page = $resolvePage();

                    return new HorizonScrollMetadata(
                        'before_id',
                        null,
                        $page->next,
                        $page->current,
                    );
                },
            )->matchOn('data.id'),
        ]);
    }

    public function show(BatchesData $batches, string $batch): Response
    {
        $detail = $batches->find($batch);

        abort_if($detail === null, 404);

        return Inertia::render('Batches/Show', [
            'meta' => new PageMetaData($detail->displayName, NavigationItem::Batches),
            'batch' => $detail,
        ]);
    }
}
