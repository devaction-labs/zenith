<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Controllers;

use Closure;
use DevactionLabs\Zenith\Batches\DatabaseBatchCapability;
use DevactionLabs\Zenith\Metrics\Data\MetricPreviewData;
use DevactionLabs\Zenith\Metrics\MetricsData;
use DevactionLabs\Zenith\Metrics\MetricType;
use DevactionLabs\Zenith\Queues\Data\QueueActivityPageData;
use DevactionLabs\Zenith\Queues\Data\QueueBypassWarningData;
use DevactionLabs\Zenith\Queues\Data\QueueSummaryData;
use DevactionLabs\Zenith\Queues\QueueActivityData;
use DevactionLabs\Zenith\Queues\QueueActivityTab;
use DevactionLabs\Zenith\Queues\QueueBypassWarning;
use DevactionLabs\Zenith\Queues\QueuesData;
use DevactionLabs\Zenith\Queues\QueueSummary;
use DevactionLabs\Zenith\Support\Data\PageMetaData;
use DevactionLabs\Zenith\Support\NavigationItem;
use DevactionLabs\Zenith\Support\Scrolling\HorizonScrollMetadata;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class QueueController
{
    public function index(QueuesData $queues, QueueBypassWarning $bypassWarning): Response
    {
        return Inertia::render('Queues/Index', [
            'meta' => new PageMetaData('Queues', NavigationItem::Queues),
            'queues' => $queues->all(),
            'queueBypassWarning' => fn (): QueueBypassWarningData => $bypassWarning->summary(),
        ]);
    }

    public function show(
        Request $request,
        QueuesData $queues,
        QueueSummary $summary,
        QueueActivityData $activity,
        MetricsData $metrics,
        DatabaseBatchCapability $batchCapability,
        string $queue,
    ): Response {
        $view = $request->input('view') === 'metrics' ? 'metrics' : 'overview';
        $tabValue = $request->input('tab');
        $tab = is_string($tabValue)
            ? QueueActivityTab::tryFrom($tabValue)
            : null;
        $tab ??= QueueActivityTab::Pending;
        $resolveBatchAttributionAvailable = fn (): bool => once(
            $batchCapability->attributionSupported(...),
        );

        if ($tab === QueueActivityTab::Batches && ! $resolveBatchAttributionAvailable()) {
            $tab = QueueActivityTab::Pending;
        }

        $resolveCatalog = fn () => once(fn () => $queues->all());
        $resolveRow = fn () => once(fn () => $resolveCatalog()->find($queue));
        $resolveUnavailableMessage = fn () => once(
            fn () => $resolveCatalog()->message
                ?? 'This queue is no longer supervised by Horizon.',
        );

        if (! $this->isPartialReload($request)) {
            $catalog = $resolveCatalog();

            abort_if($catalog->available && $resolveRow() === null, 404);
        }

        $cursor = $this->cursor($request, $tab);
        $requestsOnlyActivity = $this->requestsOnlyActivity($request);
        $requestsOnlyPreview = $this->requestsOnlyPreview($request);
        $verifyQueueAvailability = ! $requestsOnlyActivity && ! $requestsOnlyPreview;
        $resolveSummary = fn (): QueueSummaryData => once(
            fn () => ($row = $resolveRow()) === null
                ? QueueSummary::unavailable($queue, $resolveUnavailableMessage())
                : $summary->forQueue($row),
        );
        $resolveActivity = fn (): QueueActivityPageData => once(
            fn () => $verifyQueueAvailability && $resolveRow() === null
                ? QueueActivityPageData::unavailable(
                    $this->pageName($tab),
                    $resolveUnavailableMessage(),
                )
                : $activity->page($queue, $tab, $cursor),
        );
        $resolvePreview = fn (): ?MetricPreviewData => once(
            fn () => $view !== 'metrics'
                || ($verifyQueueAvailability && $resolveRow() === null)
                ? null
                : $metrics->preview(MetricType::Queues, $queue),
        );

        return $this->detailResponse(
            queue: $queue,
            view: $view,
            tab: $tab,
            summary: $resolveSummary,
            activity: $resolveActivity,
            preview: $resolvePreview,
            batchAttributionAvailable: $resolveBatchAttributionAvailable,
            querySignature: fn (): string => $activity->querySignature($queue, $tab),
            listRevision: fn (): string => $activity->listRevision(
                $queue,
                $tab,
                $resolveActivity,
            ),
        );
    }

    /**
     * @param  Closure(): QueueSummaryData  $summary
     * @param  Closure(): QueueActivityPageData  $activity
     * @param  Closure(): ?MetricPreviewData  $preview
     * @param  Closure(): bool  $batchAttributionAvailable
     * @param  Closure(): string  $querySignature
     * @param  Closure(): string  $listRevision
     */
    private function detailResponse(
        string $queue,
        string $view,
        QueueActivityTab $tab,
        Closure $summary,
        Closure $activity,
        Closure $preview,
        Closure $batchAttributionAvailable,
        Closure $querySignature,
        Closure $listRevision,
    ): Response {
        $metricPreview = function () use ($preview): ?array {
            $resolvedPreview = $preview();

            return $resolvedPreview === null ? null : [
                'data' => $resolvedPreview->snapshots,
                'available' => $resolvedPreview->available,
                'message' => $resolvedPreview->message,
            ];
        };
        $activityScroll = Inertia::scroll(
            function () use ($activity): array {
                $resolvedActivity = $activity();

                return [
                    'data' => $resolvedActivity->rows,
                    'total' => $resolvedActivity->total,
                    'complete' => $resolvedActivity->complete,
                    'available' => $resolvedActivity->available,
                    'warming' => $resolvedActivity->warming,
                    'message' => $resolvedActivity->message,
                ];
            },
            'data',
            function (array $_value) use ($activity): HorizonScrollMetadata {
                $resolvedActivity = $activity();

                return new HorizonScrollMetadata(
                    $resolvedActivity->pageName,
                    null,
                    $resolvedActivity->next,
                    $resolvedActivity->current,
                );
            },
        )->matchOn('data.id');
        $queueData = [
            'listRevision' => $listRevision,
            'preview' => $view === 'metrics' ? $metricPreview : null,
            'batchAttributionAvailable' => $batchAttributionAvailable,
            'activity' => $activityScroll,
            'summary' => $summary,
        ];

        return Inertia::render('Queues/Show', [
            'meta' => new PageMetaData($queue, NavigationItem::Queues),
            'queue' => $queue,
            'view' => $view,
            'tab' => $tab->value,
            'querySignature' => $querySignature,
            ...$queueData,
        ]);
    }

    private function isPartialReload(Request $request): bool
    {
        return $request->header('X-Inertia-Partial-Component') === 'Queues/Show';
    }

    private function requestsOnlyActivity(Request $request): bool
    {
        $queueSpecificProps = $this->requestedQueueProps($request);

        return $queueSpecificProps !== []
            && array_diff($queueSpecificProps, ['activity', 'listRevision']) === [];
    }

    private function requestsOnlyPreview(Request $request): bool
    {
        $queueSpecificProps = $this->requestedQueueProps($request);

        return in_array('preview', $queueSpecificProps, true)
            && array_diff($queueSpecificProps, ['preview']) === [];
    }

    /** @return list<string> */
    private function requestedQueueProps(Request $request): array
    {
        if (! $this->isPartialReload($request)) {
            return [];
        }

        $requestedProps = array_filter(
            array_map(
                trim(...),
                explode(',', (string) $request->header('X-Inertia-Partial-Data')),
            ),
        );

        return array_values(array_intersect(
            $requestedProps,
            ['activity', 'listRevision', 'summary', 'preview'],
        ));
    }

    private function pageName(QueueActivityTab $tab): string
    {
        return $tab === QueueActivityTab::Batches ? 'before_id' : 'starting_at';
    }

    private function cursor(
        Request $request,
        QueueActivityTab $tab,
    ): int|string|null {
        $cursor = $tab === QueueActivityTab::Batches
            ? $request->input('before_id')
            : $request->query('starting_at');

        return is_int($cursor) || is_string($cursor) ? $cursor : null;
    }
}
