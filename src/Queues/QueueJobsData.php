<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Queues;

use Carbon\CarbonImmutable;
use Closure;
use DevactionLabs\HorizonNewDawn\FailedJobs\FailedJobsData;
use DevactionLabs\HorizonNewDawn\Jobs\Data\JobIndexFiltersData;
use DevactionLabs\HorizonNewDawn\Jobs\Data\JobRowData;
use DevactionLabs\HorizonNewDawn\Jobs\JobsData;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobIndexWarming;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobQuery;
use DevactionLabs\HorizonNewDawn\Jobs\RetainedJobType;
use DevactionLabs\HorizonNewDawn\Queues\Data\QueueActivityPageData;
use DevactionLabs\HorizonNewDawn\Queues\Data\QueueRetainedJobsData;
use DevactionLabs\HorizonNewDawn\Support\PollInterval;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

use function Illuminate\Support\defer;

final readonly class QueueJobsData
{
    private const int SOURCE_PAGE_SIZE = 50;

    private const int RESULT_PAGE_SIZE = 50;

    private const int SCAN_LIMIT = 250;

    public function __construct(
        private JobRepository $repository,
        private JobsData $jobs,
        private FailedJobsData $failedJobs,
        private CacheFactory $cache,
        private ?RetainedJobQuery $retainedQuery = null,
    ) {}

    public function page(
        string $queue,
        QueueActivityTab $tab,
        int|string|null $afterIndex,
    ): QueueActivityPageData {
        if ($tab === QueueActivityTab::Batches) {
            return QueueActivityPageData::unavailable(
                'before_id',
                'Retained batches are currently unavailable.',
            );
        }

        if ($this->retainedQuery !== null) {
            return $this->retainedPage($queue, $tab, $afterIndex);
        }

        try {
            $cursor = is_numeric($afterIndex) ? (int) $afterIndex : -1;
            $current = $cursor;
            $retainedReferenceCount = $this->retainedReferenceCount($tab);
            $scanned = 0;
            $rows = [];
            $next = null;
            $exhausted = $cursor >= $retainedReferenceCount - 1;
            $fullyHydrated = $cursor === -1;
            $message = null;

            while (! $exhausted && $scanned < self::SCAN_LIMIT && count($rows) < self::RESULT_PAGE_SIZE) {
                $rawPageSize = min(
                    self::SOURCE_PAGE_SIZE,
                    self::SCAN_LIMIT - $scanned,
                    $retainedReferenceCount - ($cursor + 1),
                );
                $source = $this->source($tab, $cursor);
                $fullyHydrated = $fullyHydrated && $source->count() === $rawPageSize;

                foreach ($source->take($rawPageSize) as $job) {
                    if (! is_object($job)) {
                        continue;
                    }

                    if (($job->queue ?? null) !== $queue) {
                        continue;
                    }

                    $row = $this->row($tab, $job);

                    if ($row !== null) {
                        $rows[] = $row;
                    }
                }

                $scanned += $rawPageSize;
                $cursor += $rawPageSize;
                $exhausted = $cursor >= $retainedReferenceCount - 1;
                $next = $exhausted ? null : $cursor;
            }

            $complete = $exhausted && $fullyHydrated;

            if (! $complete && ($exhausted || $scanned >= self::SCAN_LIMIT)) {
                $message = 'More retained entries may exist for this queue.';
            }

            return new QueueActivityPageData(
                available: true,
                rows: $rows,
                total: count($rows),
                complete: $complete,
                pageName: 'starting_at',
                current: $current,
                next: $next,
                message: $message,
            );
        } catch (Throwable $exception) {
            report($exception);

            return QueueActivityPageData::unavailable(
                'starting_at',
                "Retained {$tab->value} jobs are currently unavailable.",
            );
        }
    }

    private function retainedPage(
        string $queue,
        QueueActivityTab $tab,
        int|string|null $afterIndex,
    ): QueueActivityPageData {
        $type = $this->retainedType($tab);
        $this->scheduleRetainedReconciliation($type);
        $cached = $this->cachedActivityPage($queue, $tab, $afterIndex);
        $revision = $this->retainedQuery?->publishedRevision($type);

        if ($revision === null) {
            return $cached['page']
                ?? QueueActivityPageData::warming('starting_at');
        }

        if ($cached !== null && $cached['revision'] === $revision) {
            return $cached['page'];
        }

        try {
            $snapshot = $this->publishedActivitySnapshot(
                $queue,
                $tab,
                $afterIndex,
            );
            $this->storeActivityPage(
                $queue,
                $tab,
                $afterIndex,
                $snapshot['revision'],
                $snapshot['page'],
            );

            return $snapshot['page'];
        } catch (RetainedJobIndexWarming) {
            return $cached['page']
                ?? QueueActivityPageData::warming('starting_at');
        } catch (Throwable $exception) {
            report($exception);

            if ($cached !== null) {
                return $cached['page'];
            }

            return QueueActivityPageData::unavailable(
                'starting_at',
                "Retained {$tab->value} jobs are currently unavailable.",
            );
        }
    }

    private function publishedRetainedPage(
        string $queue,
        QueueActivityTab $tab,
        int|string|null $afterIndex,
    ): QueueActivityPageData {
        $page = $this->retainedQuery?->pageFromPublishedIndex(
            $this->retainedType($tab),
            $this->queueFilters($queue),
            $afterIndex,
        );

        if ($page === null) {
            throw new \RuntimeException('Global queue activity is unavailable.');
        }

        $rows = [];

        foreach ($page->jobs as $job) {
            $row = $this->row($tab, $job);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return new QueueActivityPageData(
            available: true,
            rows: $rows,
            total: $page->total,
            complete: true,
            pageName: 'starting_at',
            current: $page->current,
            next: $page->next,
            message: null,
        );
    }

    /**
     * @return array{revision: string, page: QueueActivityPageData}
     */
    private function publishedActivitySnapshot(
        string $queue,
        QueueActivityTab $tab,
        int|string|null $afterIndex,
    ): array {
        $type = $this->retainedType($tab);
        $retainedQuery = $this->retainedQuery;

        if ($retainedQuery === null) {
            throw new \RuntimeException('Global queue activity is unavailable.');
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $revision = $retainedQuery->publishedRevision($type);

            if ($revision === null) {
                throw new RetainedJobIndexWarming;
            }

            $page = $this->publishedRetainedPage($queue, $tab, $afterIndex);

            if ($retainedQuery->publishedRevision($type) === $revision) {
                return [
                    'revision' => $revision,
                    'page' => $page,
                ];
            }
        }

        throw new RetainedJobIndexWarming;
    }

    /**
     * @return array{
     *     generatedAt: int,
     *     revision: string|null,
     *     page: QueueActivityPageData
     * }|null
     */
    private function cachedActivityPage(
        string $queue,
        QueueActivityTab $tab,
        int|string|null $afterIndex,
    ): ?array {
        try {
            $payload = $this->cache->store()->get(
                $this->activityCacheKey($queue, $tab, $afterIndex),
            );

            if (
                ! is_array($payload)
                || ! is_numeric($payload['generatedAt'] ?? null)
                || ! is_array($payload['page'] ?? null)
            ) {
                return null;
            }

            $revision = $payload['revision'] ?? null;

            if ($revision !== null && ! is_string($revision)) {
                return null;
            }

            return [
                'generatedAt' => (int) $payload['generatedAt'],
                'revision' => $revision,
                'page' => QueueActivityPageData::from($payload['page']),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function storeActivityPage(
        string $queue,
        QueueActivityTab $tab,
        int|string|null $afterIndex,
        string $revision,
        QueueActivityPageData $page,
    ): void {
        try {
            $this->cache->store()->forever(
                $this->activityCacheKey($queue, $tab, $afterIndex),
                [
                    'generatedAt' => CarbonImmutable::now()->timestamp,
                    'revision' => $revision,
                    'page' => $page->toArray(),
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function scheduleRetainedReconciliation(
        RetainedJobType $type,
    ): void {
        $cacheSeconds = PollInterval::retainedCacheSeconds();
        $retainedQuery = $this->retainedQuery;

        if ($retainedQuery === null) {
            return;
        }

        $scheduleKey = $this->retainedReconciliationKey($type);

        try {
            if (! $this->cache->store()->add($scheduleKey, true, $cacheSeconds)) {
                return;
            }
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        defer(
            function () use ($retainedQuery, $type): void {
                try {
                    $retainedQuery->refreshPublishedIndex($type);
                } catch (Throwable $exception) {
                    report($exception);
                }
            },
            $scheduleKey,
        );
    }

    public function querySignature(
        string $queue,
        QueueActivityTab $tab,
    ): string {
        if ($this->retainedQuery !== null && $tab !== QueueActivityTab::Batches) {
            return $this->retainedQuery->signature(
                $this->retainedType($tab),
                $this->queueFilters($queue),
            );
        }

        return hash('sha256', json_encode([
            'queue' => $queue,
            'tab' => $tab->value,
        ], JSON_THROW_ON_ERROR));
    }

    public function listRevision(
        string $queue,
        QueueActivityTab $tab,
        ?Closure $fallbackPageResolver = null,
    ): string {
        if ($this->retainedQuery === null) {
            $page = $fallbackPageResolver === null
                ? $this->page($queue, $tab, -1)
                : $fallbackPageResolver();

            return json_encode([
                $page->total,
                $page->rows[0]->id ?? null,
            ], JSON_THROW_ON_ERROR);
        }

        if ($tab === QueueActivityTab::Batches) {
            $page = $this->page($queue, $tab, -1);

            return json_encode([
                $page->total,
                $page->rows[0]->id ?? null,
            ], JSON_THROW_ON_ERROR);
        }

        $type = $this->retainedType($tab);
        $this->scheduleRetainedReconciliation($type);

        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $revision = $this->retainedQuery->publishedRevision($type);

                if ($revision === null) {
                    throw new RetainedJobIndexWarming;
                }

                $metadata = $this->retainedQuery->publishedPageMetadata(
                    $type,
                    $this->queueFilters($queue),
                );

                if ($this->retainedQuery->publishedRevision($type) === $revision) {
                    return json_encode([
                        $revision,
                        $metadata['total'],
                        $metadata['headId'],
                    ], JSON_THROW_ON_ERROR);
                }
            }
        } catch (RetainedJobIndexWarming) {
            return json_encode([null, 0, null], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            report($exception);

            return json_encode([null, 0, null], JSON_THROW_ON_ERROR);
        }

        return json_encode([null, 0, null], JSON_THROW_ON_ERROR);
    }

    public function summary(string $queue): QueueRetainedJobsData
    {
        $cacheSeconds = PollInterval::cacheSeconds();

        if ($this->retainedQuery !== null) {
            return $this->retainedSummaryWithCache(
                $queue,
                PollInterval::retainedCacheSeconds(),
            );
        }

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
        }

        return $this->buildSummary($queue);
    }

    private function retainedSummaryWithCache(
        string $queue,
        int $cacheSeconds,
    ): QueueRetainedJobsData {
        foreach (RetainedJobType::cases() as $type) {
            $this->scheduleRetainedReconciliation($type);
        }

        $cached = $this->cachedRetainedSummary($queue);
        $revisions = $this->publishedRevisions();

        if ($revisions === null) {
            return $cached['summary']
                ?? QueueRetainedJobsData::warming();
        }

        if ($cached !== null && $cached['revisions'] === $revisions) {
            if (CarbonImmutable::now()->timestamp >= $cached['generatedAt'] + $cacheSeconds) {
                $this->scheduleSummaryCacheRefresh($queue, $cacheSeconds);
            }

            return $cached['summary'];
        }

        try {
            $snapshot = $this->publishedSummarySnapshot($queue);

            if ($this->summaryIsConsistent($snapshot['summary'])) {
                $this->storeRetainedSummary(
                    $queue,
                    $snapshot['revisions'],
                    $snapshot['summary'],
                );
            }

            return $snapshot['summary'];
        } catch (RetainedJobIndexWarming) {
            return $cached['summary']
                ?? QueueRetainedJobsData::warming();
        } catch (Throwable $exception) {
            report($exception);

            if ($cached !== null) {
                return $cached['summary'];
            }

            return QueueRetainedJobsData::unavailable();
        }
    }

    /**
     * @return array{
     *     revisions: array<string, string>,
     *     summary: QueueRetainedJobsData
     * }
     */
    private function publishedSummarySnapshot(string $queue): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $revisions = $this->publishedRevisions();

            if ($revisions === null) {
                throw new RetainedJobIndexWarming;
            }

            $summary = $this->buildSummary($queue, published: true);

            if ($this->publishedRevisions() === $revisions) {
                return [
                    'revisions' => $revisions,
                    'summary' => $summary,
                ];
            }
        }

        throw new RetainedJobIndexWarming;
    }

    /** @return array<string, string>|null */
    private function publishedRevisions(): ?array
    {
        $revisions = [];

        foreach (RetainedJobType::cases() as $type) {
            $revision = $this->retainedQuery?->publishedRevision($type);

            if ($revision === null) {
                return null;
            }

            $revisions[$type->value] = $revision;
        }

        return $revisions;
    }

    /**
     * @return array{
     *     generatedAt: int,
     *     revisions: array<string, string>,
     *     summary: QueueRetainedJobsData
     * }|null
     */
    private function cachedRetainedSummary(string $queue): ?array
    {
        try {
            $payload = $this->cache->store()->get($this->cacheKey($queue));

            if (! is_array($payload)) {
                return null;
            }

            if (
                is_numeric($payload['generatedAt'] ?? null)
                && is_array($payload['summary'] ?? null)
            ) {
                $revisions = [];

                if (is_array($payload['revisions'] ?? null)) {
                    foreach (RetainedJobType::cases() as $type) {
                        $revision = $payload['revisions'][$type->value] ?? null;

                        if (! is_string($revision)) {
                            return null;
                        }

                        $revisions[$type->value] = $revision;
                    }
                }

                $summary = $this->summaryFromPayload($payload['summary']);

                return $summary === null
                    ? null
                    : [
                        'generatedAt' => (int) $payload['generatedAt'],
                        'revisions' => $revisions,
                        'summary' => $summary,
                    ];
            }

            $legacySummary = $this->summaryFromPayload($payload);

            return $legacySummary === null
                ? null
                : [
                    'generatedAt' => 0,
                    'revisions' => [],
                    'summary' => $legacySummary,
                ];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /** @param array<string, string> $revisions */
    private function storeRetainedSummary(
        string $queue,
        array $revisions,
        QueueRetainedJobsData $summary,
    ): void {
        try {
            $this->cache->store()->forever(
                $this->cacheKey($queue),
                [
                    'generatedAt' => CarbonImmutable::now()->timestamp,
                    'revisions' => $revisions,
                    'summary' => $summary->toArray(),
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function scheduleSummaryCacheRefresh(
        string $queue,
        int $cacheSeconds,
    ): void {
        $scheduleKey = $this->summaryRefreshKey($queue);

        try {
            if (! $this->cache->store()->add($scheduleKey, true, $cacheSeconds)) {
                return;
            }
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        defer(
            function () use ($queue): void {
                try {
                    $snapshot = $this->publishedSummarySnapshot($queue);

                    if ($this->summaryIsConsistent($snapshot['summary'])) {
                        $this->storeRetainedSummary(
                            $queue,
                            $snapshot['revisions'],
                            $snapshot['summary'],
                        );
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            },
            $scheduleKey,
        );
    }

    private function summaryIsConsistent(QueueRetainedJobsData $summary): bool
    {
        return ! $summary->warming
            && $summary->message === null
            && $summary->completedAvailable
            && $summary->pendingComplete
            && $summary->completedComplete
            && $summary->failedComplete
            && $summary->silencedComplete;
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
    private function summaryFromPayload(array $payload): ?QueueRetainedJobsData
    {
        try {
            return QueueRetainedJobsData::from($payload);
        } catch (Throwable) {
            return null;
        }
    }

    private function buildSummary(
        string $queue,
        bool $published = false,
    ): QueueRetainedJobsData {
        $pending = $this->safeSummary($queue, QueueActivityTab::Pending, $published);
        $completed = $this->safeSummary($queue, QueueActivityTab::Completed, $published);
        $failed = $this->safeSummary($queue, QueueActivityTab::Failed, $published);
        $silenced = $this->safeSummary($queue, QueueActivityTab::Silenced, $published);
        $available = $pending['available']
            && $failed['available']
            && $silenced['available'];
        $completedRetentionMinutes = $this->retentionMinutes(QueueActivityTab::Completed);
        $failedRetentionMinutes = $this->retentionMinutes(QueueActivityTab::Failed);

        return new QueueRetainedJobsData(
            pending: $pending['total'] ?? 0,
            pendingComplete: $pending['complete'],
            completed: $completed['total'],
            completedAvailable: $completed['available'],
            completedComplete: $completed['complete'],
            completedPerMinute: $completed['hour'] === null
                ? null
                : round(
                    $completed['hour'] / max(1, min(60, $completedRetentionMinutes)),
                    2,
                ),
            completedPerMinuteComplete: $completed['complete'],
            completedPastHour: $completed['hour'],
            completedPastHourComplete: $completed['complete'],
            completedPastDay: $completed['day'],
            completedPastDayComplete: $completed['complete'],
            completedRetentionMinutes: $completedRetentionMinutes,
            failed: $failed['total'] ?? 0,
            failedComplete: $failed['complete'],
            failedPerMinute: round(
                ($failed['hour'] ?? 0) / max(1, min(60, $failedRetentionMinutes)),
                2,
            ),
            failedPerMinuteComplete: $failed['complete'],
            failedPastHour: $failed['hour'] ?? 0,
            failedPastHourComplete: $failed['complete'],
            failedPastDay: $failed['day'] ?? 0,
            failedPastDayComplete: $failed['complete'],
            failedRetentionMinutes: $failedRetentionMinutes,
            silenced: $silenced['total'] ?? 0,
            silencedComplete: $silenced['complete'],
            message: $available ? null : 'Some retained job data is currently unavailable.',
        );
    }

    /** @return array{total: int|null, complete: bool, hour: int|null, day: int|null, available: bool} */
    private function safeSummary(
        string $queue,
        QueueActivityTab $tab,
        bool $published = false,
    ): array {
        try {
            return $this->scanSummary($queue, $tab, $published);
        } catch (RetainedJobIndexWarming $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return [
                'total' => $tab === QueueActivityTab::Completed ? null : 0,
                'complete' => false,
                'hour' => $tab === QueueActivityTab::Completed ? null : 0,
                'day' => $tab === QueueActivityTab::Completed ? null : 0,
                'available' => false,
            ];
        }
    }

    /** @return array{total: int, complete: bool, hour: int, day: int, available: bool} */
    private function scanSummary(
        string $queue,
        QueueActivityTab $tab,
        bool $published = false,
    ): array {
        if ($this->retainedQuery !== null) {
            return $this->retainedSummary($queue, $tab, $published);
        }

        $cursor = -1;
        $retainedReferenceCount = $this->retainedReferenceCount($tab);
        $scanned = 0;
        $total = 0;
        $hour = 0;
        $day = 0;
        $exhausted = $retainedReferenceCount === 0;
        $fullyHydrated = true;
        $retentionMinutes = $this->retentionMinutes($tab);
        $hourCutoff = CarbonImmutable::now()
            ->subMinutes(min(60, $retentionMinutes))
            ->getTimestamp();
        $dayCutoff = CarbonImmutable::now()
            ->subMinutes(min(1440, $retentionMinutes))
            ->getTimestamp();

        while (! $exhausted && $scanned < self::SCAN_LIMIT) {
            $rawPageSize = min(
                self::SOURCE_PAGE_SIZE,
                self::SCAN_LIMIT - $scanned,
                $retainedReferenceCount - ($cursor + 1),
            );
            $source = $this->source($tab, $cursor);
            $fullyHydrated = $fullyHydrated && $source->count() === $rawPageSize;

            foreach ($source->take($rawPageSize) as $job) {
                if (! is_object($job)) {
                    continue;
                }

                if (($job->queue ?? null) !== $queue) {
                    continue;
                }

                $total++;
                $timestamp = $this->periodTimestamp($tab, $job);

                if ($timestamp !== null && $timestamp >= $dayCutoff) {
                    $day++;
                }

                if ($timestamp !== null && $timestamp >= $hourCutoff) {
                    $hour++;
                }
            }

            $scanned += $rawPageSize;
            $cursor += $rawPageSize;
            $exhausted = $cursor >= $retainedReferenceCount - 1;
        }

        return [
            'total' => $total,
            'complete' => $exhausted && $fullyHydrated,
            'hour' => $hour,
            'day' => $day,
            'available' => true,
        ];
    }

    /** @return array{total: int, complete: bool, hour: int, day: int, available: bool} */
    private function retainedSummary(
        string $queue,
        QueueActivityTab $tab,
        bool $published,
    ): array {
        $retentionMinutes = $this->retentionMinutes($tab);
        $hourCutoff = CarbonImmutable::now()
            ->subMinutes(min(60, $retentionMinutes))
            ->getTimestamp();
        $dayCutoff = CarbonImmutable::now()
            ->subMinutes(min(1440, $retentionMinutes))
            ->getTimestamp();
        $counts = $published
            ? $this->retainedQuery?->periodCountsFromPublishedIndex(
                $this->retainedType($tab),
                $this->queueFilters($queue),
                $hourCutoff,
                $dayCutoff,
            )
            : $this->retainedQuery?->periodCounts(
                $this->retainedType($tab),
                $this->queueFilters($queue),
                $hourCutoff,
                $dayCutoff,
            );

        if ($counts === null) {
            throw new \RuntimeException('Global queue summary is unavailable.');
        }

        return [
            'total' => $counts['total'],
            'complete' => true,
            'hour' => $counts['hour'],
            'day' => $counts['day'],
            'available' => true,
        ];
    }

    private function retainedType(QueueActivityTab $tab): RetainedJobType
    {
        return match ($tab) {
            QueueActivityTab::Pending => RetainedJobType::Pending,
            QueueActivityTab::Completed => RetainedJobType::Completed,
            QueueActivityTab::Failed => RetainedJobType::Failed,
            QueueActivityTab::Silenced => RetainedJobType::Silenced,
            QueueActivityTab::Batches => throw new \InvalidArgumentException(
                'Batches are not retained jobs.',
            ),
        };
    }

    private function queueFilters(string $queue): JobIndexFiltersData
    {
        return new JobIndexFiltersData(
            job: null,
            queue: $queue,
            connection: null,
            state: null,
        );
    }

    /** @return Collection<int, mixed> */
    private function source(QueueActivityTab $tab, int $cursor): Collection
    {
        $jobs = match ($tab) {
            QueueActivityTab::Pending => $this->repository->getPending((string) $cursor),
            QueueActivityTab::Completed => $this->repository->getCompleted((string) $cursor),
            QueueActivityTab::Failed => $this->repository->getFailed((string) $cursor),
            QueueActivityTab::Silenced => $this->repository->getSilenced((string) $cursor),
            QueueActivityTab::Batches => collect(),
        };

        return $jobs;
    }

    private function retainedReferenceCount(QueueActivityTab $tab): int
    {
        $count = match ($tab) {
            QueueActivityTab::Pending => $this->repository->countPending(),
            QueueActivityTab::Completed => $this->repository->countCompleted(),
            QueueActivityTab::Failed => $this->repository->countFailed(),
            QueueActivityTab::Silenced => $this->repository->countSilenced(),
            QueueActivityTab::Batches => 0,
        };

        return max(0, (int) $count);
    }

    private function row(QueueActivityTab $tab, object $job): ?JobRowData
    {
        return $tab === QueueActivityTab::Failed
            ? $this->failedJobs->row($job)
            : $this->jobs->row($job);
    }

    private function periodTimestamp(QueueActivityTab $tab, object $job): ?int
    {
        $value = match ($tab) {
            QueueActivityTab::Pending => $job->reserved_at ?? null,
            QueueActivityTab::Completed => $job->completed_at ?? null,
            QueueActivityTab::Failed => $job->failed_at ?? null,
            QueueActivityTab::Silenced => $job->completed_at ?? null,
            QueueActivityTab::Batches => null,
        };

        return is_numeric($value) ? (int) $value : null;
    }

    private function cacheKey(string $queue): string
    {
        $prefix = config('horizon.prefix', 'horizon:');
        $prefix = is_string($prefix) ? $prefix : 'horizon:';

        return 'horizon-new-dawn:queue-jobs:'.hash('sha256', $prefix."\0".$queue);
    }

    private function activityCacheKey(
        string $queue,
        QueueActivityTab $tab,
        int|string|null $afterIndex,
    ): string {
        $prefix = config('horizon.prefix', 'horizon:');
        $prefix = is_string($prefix) ? $prefix : 'horizon:';

        return 'horizon-new-dawn:queue-activity:'.hash(
            'sha256',
            implode("\0", [
                $prefix,
                $queue,
                $tab->value,
                (string) ($afterIndex ?? ''),
            ]),
        );
    }

    private function retainedReconciliationKey(
        RetainedJobType $type,
    ): string {
        $prefix = config('horizon.prefix', 'horizon:');
        $prefix = is_string($prefix) ? $prefix : 'horizon:';

        return 'horizon-new-dawn:retained-reconciliation:'.hash(
            'sha256',
            $prefix."\0".$type->value,
        );
    }

    private function summaryRefreshKey(string $queue): string
    {
        return $this->cacheKey($queue).':refresh';
    }

    private function retentionMinutes(QueueActivityTab $tab): int
    {
        $minutes = match ($tab) {
            QueueActivityTab::Completed, QueueActivityTab::Silenced => config('horizon.trim.completed', 60),
            QueueActivityTab::Failed => config('horizon.trim.failed', 10080),
            QueueActivityTab::Pending => config('horizon.trim.pending', 60),
            QueueActivityTab::Batches => 0,
        };

        return max(0, (int) $minutes);
    }
}
