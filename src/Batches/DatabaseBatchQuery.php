<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Batches;

use Illuminate\Database\Query\Builder;
use JsonException;
use NckRtl\HorizonNewDawn\Batches\Data\BatchFilterCatalogData;
use NckRtl\HorizonNewDawn\Batches\Data\BatchIndexFiltersData;
use NckRtl\HorizonNewDawn\Batches\Data\BatchPageData;
use NckRtl\HorizonNewDawn\Batches\Data\BatchRowData;
use NckRtl\HorizonNewDawn\Batches\Data\BatchStatusCountsData;
use NckRtl\HorizonNewDawn\Dashboard\Data\DashboardBatchPreviewData;
use NckRtl\HorizonNewDawn\Queues\Data\QueueRetainedBatchesData;
use RuntimeException;

final readonly class DatabaseBatchQuery
{
    private const int PAGE_SIZE = 50;

    private const int CLEAR_CANDIDATE_CHUNK_SIZE = 500;

    public function __construct(
        private DatabaseBatchCapability $capability,
        private DatabaseBatchMetadataSynchronizer $synchronizer,
    ) {}

    public function supported(): bool
    {
        return $this->capability->supported();
    }

    public function attributionSupported(): bool
    {
        return $this->capability->attributionSupported();
    }

    public function attributionMessage(): ?string
    {
        return $this->capability->attributionMessage();
    }

    public function page(BatchIndexFiltersData $filters, ?string $cursor): BatchPageData
    {
        $this->ensureSupported();
        $withAttribution = $filters->queue !== null || $filters->connection !== null;

        if ($withAttribution) {
            $this->ensureAttributionSupported();
            $this->synchronizer->sync();
        }

        $filtered = $this->applyFilters(
            $this->rowsQuery($withAttribution),
            $filters,
            includeStatus: false,
        );
        $rows = $this->applyStatus(clone $filtered, $filters->status);
        $sortColumn = $this->sortColumn($filters->sort);
        $decodedCursor = $this->decodeCursor($cursor, $filters);

        if ($decodedCursor !== null) {
            $operator = $filters->direction === BatchSortDirection::Ascending ? '>' : '<';
            $rows->where(function (Builder $query) use (
                $decodedCursor,
                $sortColumn,
                $operator,
            ): void {
                $query
                    ->where($sortColumn, $operator, $decodedCursor['value'])
                    ->orWhere(function (Builder $query) use (
                        $decodedCursor,
                        $sortColumn,
                        $operator,
                    ): void {
                        $query
                            ->where($sortColumn, '=', $decodedCursor['value'])
                            ->where('id', $operator, $decodedCursor['id']);
                    });
            });
        }

        $direction = $filters->direction->value;
        $source = $rows
            ->orderBy($sortColumn, $direction)
            ->orderBy('id', $direction)
            ->limit(self::PAGE_SIZE + 1)
            ->get()
            ->map(DatabaseBatchQueryRow::from(...));
        $hasMore = $source->count() > self::PAGE_SIZE;
        $source = $source->take(self::PAGE_SIZE)->values();
        $pageRows = $source->map($this->row(...))->all();
        $last = $source->last();

        return new BatchPageData(
            available: true,
            batches: $pageRows,
            complete: true,
            current: $cursor,
            next: $hasMore ? $this->cursorForRow($last, $filters) : null,
            message: null,
        );
    }

    public function statusCounts(BatchIndexFiltersData $filters): BatchStatusCountsData
    {
        $this->ensureSupported();
        $withAttribution = $filters->queue !== null || $filters->connection !== null;

        if ($withAttribution) {
            $this->ensureAttributionSupported();
            $this->synchronizer->sync();
        }

        $filtered = $this->applyFilters(
            $this->rowsQuery($withAttribution),
            $filters,
            includeStatus: false,
        );

        return $this->aggregateStatusCounts($filtered);
    }

    public function catalog(): BatchFilterCatalogData
    {
        $this->ensureAttributionSupported();
        $this->synchronizer->sync();
        $rows = $this->rowsQuery(withAttribution: true);

        return new BatchFilterCatalogData(
            available: true,
            complete: true,
            message: null,
            queues: $this->distinctValues(clone $rows, 'queue'),
            connections: $this->distinctValues(clone $rows, 'connection'),
        );
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     complete: bool,
     *     message: null,
     *     previews: list<array{id: string, name: string, progress: int}>
     * }
     */
    public function overview(): array
    {
        $this->ensureSupported();
        $rows = $this->rowsQuery(withAttribution: false);
        $previewRows = (clone $rows)
            ->whereNull('cancelled_at')
            ->whereColumn('pending_jobs', '>', 'failed_jobs')
            ->orderByDesc('progress')
            ->orderByDesc('id')
            ->limit(3)
            ->get();
        $previews = [];

        foreach ($previewRows as $previewRow) {
            $data = $this->row(DatabaseBatchQueryRow::from($previewRow));
            $previews[] = [
                'id' => $data->id,
                'name' => $data->displayName,
                'progress' => $data->progress,
            ];
        }

        $counts = (clone $rows)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                'SUM(CASE WHEN cancelled_at IS NULL'
                .' AND pending_jobs > failed_jobs THEN 1 ELSE 0 END) as active',
            )
            ->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'active' => (int) ($counts->active ?? 0),
            'complete' => true,
            'message' => null,
            'previews' => $previews,
        ];
    }

    public function queueSummary(string $queue): QueueRetainedBatchesData
    {
        $this->ensureAttributionSupported();
        $this->synchronizer->sync();
        $rows = $this->rowsQuery(withAttribution: true)
            ->where('queue', $queue);
        $active = (clone $rows)
            ->whereNull('cancelled_at')
            ->whereColumn('pending_jobs', '>', 'failed_jobs');
        $previewRows = (clone $active)
            ->orderByDesc('progress')
            ->orderByDesc('id')
            ->limit(3)
            ->get();
        $previews = [];

        foreach ($previewRows as $previewRow) {
            $data = $this->row(DatabaseBatchQueryRow::from($previewRow));
            $previews[] = new DashboardBatchPreviewData(
                id: $data->id,
                name: $data->displayName,
                progress: $data->progress,
            );
        }

        $counts = (clone $rows)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                'SUM(CASE WHEN cancelled_at IS NULL'
                .' AND pending_jobs > failed_jobs THEN 1 ELSE 0 END) as active',
            )
            ->first();

        return new QueueRetainedBatchesData(
            total: (int) ($counts->total ?? 0),
            active: (int) ($counts->active ?? 0),
            previews: $previews,
            complete: true,
            message: null,
        );
    }

    /**
     * Stream failed job IDs for batches attributed to a queue. Callers that need
     * uniqueness should rely on Redis snapshot membership or collect deliberately.
     *
     * @return \Generator<int, string>
     */
    public function failedJobIdsForQueue(string $queue): \Generator
    {
        $this->ensureAttributionSupported();
        $this->synchronizer->sync();

        $connection = $this->capability->connection();
        $sourceTable = $this->capability->sourceTable();
        $lastId = null;

        do {
            $rows = $connection->table("{$sourceTable} as source")
                ->join(
                    DatabaseBatchCapability::METADATA_TABLE.' as metadata',
                    'metadata.batch_id',
                    '=',
                    'source.id',
                )
                ->where('metadata.queue', $queue)
                ->where('source.failed_jobs', '>', 0)
                ->when(
                    $lastId !== null,
                    static fn (Builder $query): Builder => $query->where(
                        'source.id',
                        '>',
                        $lastId,
                    ),
                )
                ->orderBy('source.id')
                ->limit(self::PAGE_SIZE)
                ->get(['source.id', 'source.failed_job_ids']);

            foreach ($rows as $row) {
                foreach ($this->failedJobIds($row) as $failedJobId) {
                    yield $failedJobId;
                }
            }

            $last = $rows->last();
            $lastId = is_object($last) && is_string($last->id ?? null)
                ? $last->id
                : null;

            if ($rows->isNotEmpty() && $lastId === null) {
                throw new RuntimeException(
                    'The batch retry query could not advance through the retained batches.',
                );
            }
        } while ($rows->count() === self::PAGE_SIZE);
    }

    /** @return iterable<int, list<DatabaseBatchClearCandidate>> */
    public function clearCandidateChunks(): iterable
    {
        $this->ensureSupported();

        $connection = $this->capability->connection();
        $sourceTable = $this->capability->sourceTable();
        $lastId = null;

        do {
            $rows = $connection->table($sourceTable)
                ->useWritePdo()
                ->where('id', '!=', '')
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNotNull('cancelled_at')
                                ->where('pending_jobs', 0);
                        })
                        ->orWhere(function (Builder $query): void {
                            $query
                                ->whereNull('cancelled_at')
                                ->whereNotNull('finished_at');
                        })
                        ->orWhere(function (Builder $query): void {
                            $query
                                ->whereNull('cancelled_at')
                                ->whereNull('finished_at')
                                ->where('failed_jobs', '>', 0)
                                ->whereColumn('pending_jobs', '<=', 'failed_jobs');
                        });
                })
                ->when(
                    $lastId !== null,
                    static fn (Builder $query): Builder => $query->where('id', '>', $lastId),
                )
                ->orderBy('id')
                ->limit(self::CLEAR_CANDIDATE_CHUNK_SIZE)
                ->get([
                    'id',
                    'failed_jobs',
                    'failed_job_ids',
                    'cancelled_at',
                    'finished_at',
                ]);
            $candidates = [];

            foreach ($rows as $row) {
                $id = is_string($row->id ?? null) ? $row->id : '';

                if ($id === '') {
                    continue;
                }

                $candidates[] = new DatabaseBatchClearCandidate(
                    id: $id,
                    scope: ($row->cancelled_at ?? null) !== null
                        ? BatchClearScope::Cancelled
                        : (($row->finished_at ?? null) !== null
                            ? BatchClearScope::Complete
                            : BatchClearScope::Incomplete),
                    failedJobIds: $this->clearCandidateFailedJobIds($row),
                );
            }

            if ($candidates !== []) {
                yield $candidates;
            }

            $last = $rows->last();
            $nextId = is_object($last) && is_string($last->id ?? null)
                ? $last->id
                : null;

            if ($rows->isNotEmpty()
                && ($nextId === null || $nextId === ''
                    || ($lastId !== null && strcmp($nextId, $lastId) <= 0))
            ) {
                throw new RuntimeException(
                    'The database batch clear query could not advance through the retained batches.',
                );
            }

            $lastId = $nextId;
        } while ($rows->count() === self::CLEAR_CANDIDATE_CHUNK_SIZE);
    }

    public function attribution(string $batchId): ?DatabaseBatchMetadata
    {
        $this->ensureAttributionSupported();

        return $this->synchronizer->syncBatch($batchId);
    }

    private function rowsQuery(bool $withAttribution): Builder
    {
        $connection = $this->capability->connection();
        $sourceTable = $this->capability->sourceTable();
        $failedCount = 'CASE'
            .' WHEN source.pending_jobs <= 0 OR source.failed_jobs <= 0 THEN 0'
            .' WHEN source.pending_jobs < source.failed_jobs THEN source.pending_jobs'
            .' ELSE source.failed_jobs END';
        $pendingCount = 'CASE'
            .' WHEN source.pending_jobs <= 0 THEN 0'
            .' WHEN source.failed_jobs <= 0 THEN source.pending_jobs'
            .' WHEN source.pending_jobs > source.failed_jobs'
            .' THEN source.pending_jobs - source.failed_jobs'
            .' ELSE 0 END';
        $progress = 'CASE WHEN source.total_jobs > 0'
            .' THEN ROUND(((source.total_jobs - source.pending_jobs) * 100.0) / source.total_jobs)'
            .' ELSE 0 END';
        $status = 'CASE'
            ." WHEN source.cancelled_at IS NOT NULL THEN 'cancelled'"
            ." WHEN source.pending_jobs = 0 THEN 'finished'"
            ." WHEN source.failed_jobs > 0 THEN 'failures'"
            ." ELSE 'pending' END";
        $sortName = "LOWER(CASE WHEN TRIM(source.name) = '' THEN source.id ELSE source.name END)";
        $queueActivityRank = 'CASE'
            .' WHEN source.cancelled_at IS NULL'
            .' AND source.pending_jobs > source.failed_jobs'
            ." THEN 101 + ({$progress})"
            .' ELSE 0 END';
        $base = $connection->table("{$sourceTable} as source");

        if ($withAttribution) {
            $base->leftJoin(
                DatabaseBatchCapability::METADATA_TABLE.' as metadata',
                'metadata.batch_id',
                '=',
                'source.id',
            );
        }

        $base->select([
            'source.id',
            'source.name',
            'source.total_jobs',
            'source.pending_jobs',
            'source.failed_jobs',
            'source.created_at',
            'source.cancelled_at',
            'source.finished_at',
        ]);

        if ($withAttribution) {
            $base->addSelect([
                'metadata.queue',
                'metadata.connection',
                'metadata.queue_is_explicit',
                'metadata.connection_is_explicit',
            ])->selectRaw('1 as attribution_captured');
        } else {
            $base
                ->selectRaw('NULL as queue')
                ->selectRaw('NULL as connection')
                ->selectRaw('0 as queue_is_explicit')
                ->selectRaw('0 as connection_is_explicit')
                ->selectRaw('0 as attribution_captured');
        }

        $base
            ->selectRaw("{$pendingCount} as pending_count")
            ->selectRaw("{$failedCount} as failed_count")
            ->selectRaw('(source.total_jobs - source.pending_jobs) as processed_count')
            ->selectRaw("{$progress} as progress")
            ->selectRaw("{$status} as status")
            ->selectRaw("{$sortName} as sort_name")
            ->selectRaw("{$queueActivityRank} as queue_activity_rank");

        return $connection->query()->fromSub($base, 'batch_rows');
    }

    private function applyFilters(
        Builder $query,
        BatchIndexFiltersData $filters,
        bool $includeStatus,
    ): Builder {
        if ($filters->query !== null && trim($filters->query) !== '') {
            $this->applyLiteralSearch($query, trim($filters->query));
        }

        if ($filters->queue !== null) {
            $query->where('queue', $filters->queue);
        }

        if ($filters->connection !== null) {
            $query->where('connection', $filters->connection);
        }

        if ($filters->created !== null) {
            $query->where('created_at', '>=', $filters->created->cutoffTimestamp());
        }

        return $includeStatus
            ? $this->applyStatus($query, $filters->status)
            : $query;
    }

    private function applyStatus(Builder $query, ?BatchStatus $status): Builder
    {
        return $status === null ? $query : $query->where('status', $status->value);
    }

    private function applyLiteralSearch(Builder $query, string $search): void
    {
        $driver = $this->capability->connection()->getDriverName();
        $expression = match ($driver) {
            'pgsql' => "POSITION(LOWER(?) IN LOWER(COALESCE(name, ''))) > 0",
            default => "INSTR(LOWER(COALESCE(name, '')), LOWER(?)) > 0",
        };
        $idExpression = match ($driver) {
            'pgsql' => 'POSITION(LOWER(?) IN LOWER(id)) > 0',
            default => 'INSTR(LOWER(id), LOWER(?)) > 0',
        };

        $query->where(function (Builder $query) use (
            $expression,
            $idExpression,
            $search,
        ): void {
            $query
                ->whereRaw($expression, [$search])
                ->orWhereRaw($idExpression, [$search]);
        });
    }

    private function aggregateStatusCounts(Builder $query): BatchStatusCountsData
    {
        $counts = [
            BatchStatus::Pending->value => 0,
            BatchStatus::Finished->value => 0,
            BatchStatus::Failures->value => 0,
            BatchStatus::Cancelled->value => 0,
        ];

        $query
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->get()
            ->each(static function (object $row) use (&$counts): void {
                $status = (string) $row->status;

                if (array_key_exists($status, $counts)) {
                    $counts[$status] = (int) $row->aggregate;
                }
            });

        return new BatchStatusCountsData(
            all: array_sum($counts),
            pending: $counts[BatchStatus::Pending->value],
            finished: $counts[BatchStatus::Finished->value],
            failures: $counts[BatchStatus::Failures->value],
            cancelled: $counts[BatchStatus::Cancelled->value],
        );
    }

    /** @return list<string> */
    private function distinctValues(
        Builder $query,
        string $column,
    ): array {
        return array_values($query
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all());
    }

    /** @return list<string> */
    private function failedJobIds(object $row): array
    {
        $encoded = $row->failed_job_ids ?? null;
        $batchId = $row->id ?? 'unknown';

        if (! is_string($encoded)) {
            return [];
        }

        try {
            $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The retained batch '.(is_string($batchId) ? $batchId : 'unknown')
                    .' has invalid failed job metadata.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $jobId): bool => is_string($jobId)
                && trim($jobId) !== '',
        ));
    }

    /** @return list<string>|null */
    private function clearCandidateFailedJobIds(object $row): ?array
    {
        $encoded = $row->failed_job_ids ?? null;
        $failedJobs = max(0, (int) ($row->failed_jobs ?? 0));

        if (! is_string($encoded)) {
            return $failedJobs === 0 ? [] : null;
        }

        try {
            $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $failedJobIds = [];

        foreach ($decoded as $failedJobId) {
            if (! is_string($failedJobId)
                || $failedJobId === ''
                || trim($failedJobId) !== $failedJobId
                || isset($failedJobIds[$failedJobId])
            ) {
                return null;
            }

            $failedJobIds[$failedJobId] = true;
        }

        if ($failedJobs > 0 && $failedJobIds === []) {
            return null;
        }

        return array_keys($failedJobIds);
    }

    private function row(DatabaseBatchQueryRow $row): BatchRowData
    {
        $name = trim($row->name);
        $connectionExplicit = $row->connectionIsExplicit;
        $queueExplicit = $row->queueIsExplicit;

        return new BatchRowData(
            id: $row->id,
            name: $name === '' ? null : $name,
            displayName: $name === '' ? $row->id : $name,
            connection: $row->connection,
            queue: $row->queue,
            totalJobs: $row->totalJobs,
            pendingJobs: $row->pendingCount,
            failedJobs: $row->failedCount,
            failedJobAttempts: max(0, $row->failedJobAttempts),
            processedJobs: $row->processedCount,
            progress: $row->progress,
            status: $row->status,
            createdAt: $row->createdAt,
            cancelledAt: $row->cancelledAt,
            finishedAt: $row->finishedAt,
            queueExplicit: $queueExplicit,
            connectionExplicit: $connectionExplicit,
            attributionCaptured: $row->attributionCaptured,
        );
    }

    private function sortColumn(BatchSort $sort): string
    {
        return match ($sort) {
            BatchSort::Name => 'sort_name',
            BatchSort::TotalJobs => 'total_jobs',
            BatchSort::PendingJobs => 'pending_count',
            BatchSort::FailedJobs => 'failed_count',
            BatchSort::Progress => 'progress',
            BatchSort::CreatedAt => 'created_at',
            BatchSort::QueueActivity => 'queue_activity_rank',
        };
    }

    private function encodeCursor(
        int|float|string $value,
        string $id,
        BatchIndexFiltersData $filters,
    ): string {
        try {
            $encoded = json_encode([
                'sort' => $filters->sort->value,
                'direction' => $filters->direction->value,
                'filters' => $this->cursorSignature($filters),
                'value' => $value,
                'id' => $id,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The batch cursor could not be encoded.', previous: $exception);
        }

        return rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
    }

    private function cursorForRow(
        ?DatabaseBatchQueryRow $row,
        BatchIndexFiltersData $filters,
    ): ?string {
        if ($row === null) {
            return null;
        }

        return $this->encodeCursor(
            $this->sortValue($row, $filters->sort),
            $row->id,
            $filters,
        );
    }

    private function sortValue(
        DatabaseBatchQueryRow $row,
        BatchSort $sort,
    ): int|string {
        $value = match ($sort) {
            BatchSort::Name => $row->sortName,
            BatchSort::TotalJobs => $row->totalJobs,
            BatchSort::PendingJobs => $row->pendingCount,
            BatchSort::FailedJobs => $row->failedCount,
            BatchSort::Progress => $row->progress,
            BatchSort::CreatedAt => $row->createdAt,
            BatchSort::QueueActivity => $row->queueActivityRank,
        };

        return $value;
    }

    /** @return array{value: int|float|string, id: string}|null */
    private function decodeCursor(
        ?string $cursor,
        BatchIndexFiltersData $filters,
    ): ?array {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['sort'] ?? null) !== $filters->sort->value
            || ($payload['direction'] ?? null) !== $filters->direction->value
            || ($payload['filters'] ?? null) !== $this->cursorSignature($filters)
            || ! is_string($payload['id'] ?? null)
            || (! is_int($payload['value'] ?? null)
                && ! is_float($payload['value'] ?? null)
                && ! is_string($payload['value'] ?? null))
        ) {
            return null;
        }

        return [
            'value' => $payload['value'],
            'id' => $payload['id'],
        ];
    }

    private function cursorSignature(BatchIndexFiltersData $filters): string
    {
        try {
            $encoded = json_encode([
                'query' => $filters->query === null ? null : trim($filters->query),
                'queue' => $filters->queue,
                'connection' => $filters->connection,
                'created' => $filters->created?->value,
                'status' => $filters->status?->value,
                'sort' => $filters->sort->value,
                'direction' => $filters->direction->value,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The batch cursor filters could not be encoded.',
                previous: $exception,
            );
        }

        return hash('sha256', $encoded);
    }

    private function ensureSupported(): void
    {
        if (! $this->supported()) {
            throw new RuntimeException(
                $this->capability->message() ?? 'Exact retained batch queries are unavailable.',
            );
        }
    }

    private function ensureAttributionSupported(): void
    {
        if (! $this->attributionSupported()) {
            throw new RuntimeException(
                $this->capability->attributionMessage()
                    ?? 'Batch queue and connection attribution are unavailable.',
            );
        }
    }
}
