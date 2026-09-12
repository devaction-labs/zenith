<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Queues;

use DevactionLabs\Zenith\Batches\BatchesData;
use DevactionLabs\Zenith\Batches\BatchSort;
use DevactionLabs\Zenith\Batches\BatchSortDirection;
use DevactionLabs\Zenith\Batches\Data\BatchIndexFiltersData;
use DevactionLabs\Zenith\Batches\DatabaseBatchQuery;
use DevactionLabs\Zenith\Dashboard\Data\DashboardBatchPreviewData;
use DevactionLabs\Zenith\Queues\Data\QueueActivityPageData;
use DevactionLabs\Zenith\Queues\Data\QueueRetainedBatchesData;
use DevactionLabs\Zenith\Support\PollInterval;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Throwable;

final readonly class QueueBatchesData
{
    private const int SOURCE_PAGE_SIZE = 50;

    private const int RESULT_PAGE_SIZE = 50;

    private const int SCAN_LIMIT = 250;

    public function __construct(
        private BatchRepository $repository,
        private BatchesData $batches,
        private CacheFactory $cache,
        private ?DatabaseBatchQuery $databaseQuery = null,
    ) {}

    public function page(string $queue, ?string $beforeId): QueueActivityPageData
    {
        try {
            if ($this->databaseQuery !== null) {
                if (! $this->databaseQuery->attributionSupported()) {
                    return QueueActivityPageData::unavailable(
                        'before_id',
                        $this->databaseQuery->attributionMessage()
                            ?? 'Batch queue attribution is unavailable.',
                    );
                }

                $filters = new BatchIndexFiltersData(
                    query: null,
                    queue: $queue,
                    connection: null,
                    created: null,
                    status: null,
                    sort: BatchSort::QueueActivity,
                    direction: BatchSortDirection::Descending,
                );
                $page = $this->databaseQuery->page($filters, $beforeId);

                return new QueueActivityPageData(
                    available: $page->available,
                    rows: $page->batches,
                    total: $this->databaseQuery->statusCounts($filters)->all,
                    complete: $page->complete,
                    pageName: 'before_id',
                    current: $page->current,
                    next: $page->next,
                    message: $page->message,
                );
            }

            $cursor = $beforeId;
            $inspected = 0;
            $rows = [];
            $next = null;
            $complete = false;
            $message = null;

            while ($inspected < self::SCAN_LIMIT && count($rows) < self::RESULT_PAGE_SIZE) {
                $source = $this->repository->get(self::SOURCE_PAGE_SIZE, $cursor);

                if ($source === []) {
                    $complete = true;

                    break;
                }

                $nextCursor = $this->advanceCursor($source, $cursor);

                if ($nextCursor === null) {
                    $message = 'More retained entries may exist for this queue.';

                    break;
                }

                foreach ($source as $batch) {
                    if ($inspected >= self::SCAN_LIMIT) {
                        break;
                    }

                    $inspected++;

                    if ($this->batches->queue($batch) !== $queue) {
                        continue;
                    }

                    $rows[] = $this->batches->row($batch);

                    if (count($rows) === self::RESULT_PAGE_SIZE) {
                        $next = $batch->id;

                        break 2;
                    }
                }

                if ($inspected >= self::SCAN_LIMIT) {
                    break;
                }

                $cursor = $nextCursor;
                $next = $nextCursor;
            }

            if ($inspected >= self::SCAN_LIMIT) {
                $message = 'More retained entries may exist for this queue.';
            }

            return new QueueActivityPageData(
                available: true,
                rows: $rows,
                total: count($rows),
                complete: $complete,
                pageName: 'before_id',
                current: $beforeId,
                next: $next,
                message: $message,
            );
        } catch (Throwable $exception) {
            report($exception);

            return QueueActivityPageData::unavailable(
                'before_id',
                'Retained batches are currently unavailable.',
            );
        }
    }

    public function summary(string $queue): QueueRetainedBatchesData
    {
        $cacheSeconds = PollInterval::cacheSeconds();

        if ($cacheSeconds === 0) {
            return $this->buildSummary($queue);
        }

        try {
            $cache = $this->cache->store();
            $cacheKey = $this->cacheKey($queue);
            $payload = $this->rememberSummaryPayload($queue, $cacheSeconds);
            $cached = is_array($payload) ? $this->summaryFromPayload($payload) : null;

            if ($cached !== null) {
                return $cached;
            }

            $cache->forget($cacheKey);
            $payload = $this->rememberSummaryPayload($queue, $cacheSeconds);
            $cached = is_array($payload) ? $this->summaryFromPayload($payload) : null;

            return $cached ?? $this->buildSummary($queue);
        } catch (Throwable $exception) {
            report($exception);

            return $this->buildSummary($queue);
        }
    }

    private function rememberSummaryPayload(string $queue, int $cacheSeconds): mixed
    {
        return $this->cache->store()->remember(
            $this->cacheKey($queue),
            $cacheSeconds,
            fn (): array => $this->buildSummary($queue)->toArray(),
        );
    }

    /** @param array<string, mixed> $payload */
    private function summaryFromPayload(array $payload): ?QueueRetainedBatchesData
    {
        try {
            return QueueRetainedBatchesData::from($payload);
        } catch (Throwable) {
            return null;
        }
    }

    private function buildSummary(string $queue): QueueRetainedBatchesData
    {
        try {
            if ($this->databaseQuery !== null) {
                if (! $this->databaseQuery->attributionSupported()) {
                    return new QueueRetainedBatchesData(
                        total: 0,
                        active: 0,
                        previews: [],
                        complete: false,
                        message: null,
                    );
                }

                return $this->databaseQuery->queueSummary($queue);
            }

            $cursor = null;
            $inspected = 0;
            $total = 0;
            $active = 0;
            $previews = [];
            $complete = false;

            while ($inspected < self::SCAN_LIMIT) {
                $source = $this->repository->get(self::SOURCE_PAGE_SIZE, $cursor);

                if ($source === []) {
                    $complete = true;

                    break;
                }

                $nextCursor = $this->advanceCursor($source, $cursor);

                if ($nextCursor === null) {
                    break;
                }

                foreach ($source as $batch) {
                    if ($inspected >= self::SCAN_LIMIT) {
                        break;
                    }

                    $inspected++;

                    if ($this->batches->queue($batch) !== $queue) {
                        continue;
                    }

                    $total++;

                    if (! $this->active($batch)) {
                        continue;
                    }

                    $active++;
                    $this->considerPreview($previews, $batch);
                }

                $cursor = $nextCursor;
            }

            return new QueueRetainedBatchesData(
                total: $total,
                active: $active,
                previews: $previews,
                complete: $complete,
                message: null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return new QueueRetainedBatchesData(
                total: 0,
                active: 0,
                previews: [],
                complete: false,
                message: 'Retained batches are currently unavailable.',
            );
        }
    }

    /**
     * Keep at most the three best active previews (progress desc, then id desc).
     *
     * @param  list<DashboardBatchPreviewData>  $previews
     */
    private function considerPreview(array &$previews, Batch $batch): void
    {
        $row = $this->batches->row($batch);
        $previews[] = new DashboardBatchPreviewData(
            id: $row->id,
            name: $row->displayName,
            progress: $row->progress,
        );

        usort($previews, static function (
            DashboardBatchPreviewData $left,
            DashboardBatchPreviewData $right,
        ): int {
            $progress = $right->progress <=> $left->progress;

            if ($progress !== 0) {
                return $progress;
            }

            return $right->id <=> $left->id;
        });

        if (count($previews) > 3) {
            array_pop($previews);
        }
    }

    private function active(Batch $batch): bool
    {
        return ! $batch->cancelled()
            && max(0, $batch->pendingJobs - $batch->failedJobs) > 0;
    }

    /**
     * @param  array<int, Batch>  $batches
     */
    private function advanceCursor(array $batches, ?string $current): ?string
    {
        $batch = end($batches);

        if (! $batch instanceof Batch) {
            return null;
        }

        $cursor = $batch->id;

        if ($cursor === '' || ($current !== null && strcmp($cursor, $current) >= 0)) {
            return null;
        }

        return $cursor;
    }

    private function cacheKey(string $queue): string
    {
        $prefix = config('horizon.prefix', 'horizon:');
        $prefix = is_string($prefix) ? $prefix : 'horizon:';

        return 'zenith:queue-batches:'.hash('sha256', $prefix."\0".$queue);
    }
}
