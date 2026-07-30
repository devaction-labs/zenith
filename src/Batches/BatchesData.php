<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Batches;

use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use NckRtl\HorizonNewDawn\Batches\Data\BatchDetailData;
use NckRtl\HorizonNewDawn\Batches\Data\BatchIndexFiltersData;
use NckRtl\HorizonNewDawn\Batches\Data\BatchPageData;
use NckRtl\HorizonNewDawn\Batches\Data\BatchRowData;
use NckRtl\HorizonNewDawn\Batches\Data\BatchStatusCountsData;
use RuntimeException;
use Throwable;

final readonly class BatchesData
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private BatchRepository $batches,
        private BatchJobsData $batchJobs,
        private ?DatabaseBatchQuery $databaseQuery = null,
    ) {}

    public function page(
        ?string $beforeId,
        ?string $query,
        ?string $queue = null,
        ?string $connection = null,
        ?BatchCreatedRange $created = null,
        ?BatchStatus $status = null,
        BatchSort $sort = BatchSort::CreatedAt,
        BatchSortDirection $direction = BatchSortDirection::Descending,
    ): BatchPageData {
        try {
            if ($this->databaseQuery?->supported() === true) {
                return $this->databaseQuery->page(
                    new BatchIndexFiltersData(
                        query: $query,
                        queue: $queue,
                        connection: $connection,
                        created: $created,
                        status: $status,
                        sort: $sort,
                        direction: $direction,
                    ),
                    $beforeId,
                );
            }

            [$batches, $next] = $this->repositoryPage($beforeId);

            $rows = array_values(array_map($this->row(...), $batches));

            return new BatchPageData(
                available: true,
                batches: $rows,
                complete: true,
                current: $beforeId,
                next: $next,
                message: null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return new BatchPageData(
                available: false,
                batches: [],
                complete: false,
                current: $beforeId,
                next: null,
                message: 'Batches are currently unavailable.',
            );
        }
    }

    public function statusCounts(BatchIndexFiltersData $filters): BatchStatusCountsData
    {
        try {
            if ($this->databaseQuery?->supported() === true) {
                return $this->databaseQuery->statusCounts($filters);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return BatchStatusCountsData::zero();
    }

    public function find(string $id): ?BatchDetailData
    {
        try {
            $batch = $this->batches->find($id);

            if ($batch === null) {
                return null;
            }

            $row = $this->row($batch);
            $storedAttribution = $this->databaseQuery?->attributionSupported() === true
                ? $this->databaseQuery->attribution($id)
                : null;
            $jobLists = $this->batchJobs->forBatch($batch, $storedAttribution);
            $connection = $row->connection;
            $queue = $row->queue;
            $queueExplicit = $row->queueExplicit;
            $connectionExplicit = $row->connectionExplicit;

            if ($storedAttribution !== null) {
                $connection = $storedAttribution->connection;
                $queue = $storedAttribution->queue ?? 'default';
                $queueExplicit = $storedAttribution->queueIsExplicit;
                $connectionExplicit = $storedAttribution->connectionIsExplicit;
            }

            return new BatchDetailData(
                id: $row->id,
                name: $row->name,
                displayName: $row->displayName,
                connection: $connection,
                queue: $queue,
                queueExplicit: $queueExplicit,
                connectionExplicit: $connectionExplicit,
                attributionCaptured: $storedAttribution !== null,
                totalJobs: $row->totalJobs,
                pendingJobs: $row->pendingJobs,
                failedJobs: $row->failedJobs,
                failedJobAttempts: $row->failedJobAttempts,
                processedJobs: $row->processedJobs,
                progress: $row->progress,
                status: $row->status,
                createdAt: $row->createdAt,
                cancelledAt: $row->cancelledAt,
                finishedAt: $row->finishedAt,
                jobs: $jobLists,
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function row(Batch $batch): BatchRowData
    {
        $name = trim($batch->name);
        $processedJobs = $batch->processedJobs();
        $failedJobs = min(max(0, $batch->pendingJobs), max(0, $batch->failedJobs));
        $pendingJobs = max(0, $batch->pendingJobs - $failedJobs);
        $attribution = $this->attribution($batch);
        $status = match (true) {
            $batch->cancelled() => 'cancelled',
            $processedJobs === $batch->totalJobs => 'finished',
            $batch->failedJobs > 0 => 'failures',
            default => 'pending',
        };

        return new BatchRowData(
            id: $batch->id,
            name: $name === '' ? null : $name,
            displayName: $name === '' ? $batch->id : $name,
            connection: $attribution['connection'],
            queue: $attribution['queue'],
            totalJobs: $batch->totalJobs,
            pendingJobs: $pendingJobs,
            failedJobs: $failedJobs,
            failedJobAttempts: max(0, $batch->failedJobs),
            processedJobs: $processedJobs,
            progress: (int) $batch->progress(),
            status: $status,
            createdAt: $batch->createdAt->getTimestamp(),
            cancelledAt: $this->timestamp($batch->cancelledAt),
            finishedAt: $this->timestamp($batch->finishedAt),
            queueExplicit: $attribution['queueExplicit'],
            connectionExplicit: $attribution['connectionExplicit'],
        );
    }

    public function queue(Batch $batch): string
    {
        return $this->attribution($batch)['queue'];
    }

    public function connection(Batch $batch): ?string
    {
        return $this->attribution($batch)['connection'];
    }

    /**
     * @return array{
     *     connection: ?string,
     *     queue: string,
     *     queueExplicit: bool,
     *     connectionExplicit: bool
     * }
     */
    private function attribution(Batch $batch): array
    {
        $metadata = DatabaseBatchMetadata::fromOptions($batch->id, $batch->options);
        $connection = $metadata->connection;

        if ($connection === null) {
            $configuredDefault = config('queue.default');
            $connection = is_string($configuredDefault) && $configuredDefault !== ''
                ? $configuredDefault
                : null;
        }

        $queue = $metadata->queue ?? $this->configuredQueue($connection) ?? 'default';

        return [
            'connection' => $connection,
            'queue' => $queue,
            'queueExplicit' => $metadata->queueIsExplicit,
            'connectionExplicit' => $metadata->connectionIsExplicit,
        ];
    }

    private function configuredQueue(?string $connection): ?string
    {
        if ($connection === null) {
            return null;
        }

        $connections = config('queue.connections', []);

        if (! is_array($connections) || ! is_array($connections[$connection] ?? null)) {
            return null;
        }

        $queue = $connections[$connection]['queue'] ?? null;

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    /** @return array{0: array<int, Batch>, 1: ?string} */
    private function repositoryPage(?string $beforeId): array
    {
        $batches = [];
        $cursor = $beforeId;

        while (count($batches) < self::PAGE_SIZE) {
            $candidates = $this->batches->get(self::PAGE_SIZE - count($batches), $cursor);

            if ($candidates === []) {
                return [$batches, null];
            }

            $cursor = $this->advanceCursor($candidates, $cursor);
            array_push($batches, ...$candidates);
        }

        return [$batches, $cursor];
    }

    /**
     * @param  array<int, Batch>  $batches
     */
    private function advanceCursor(array $batches, ?string $current): string
    {
        $batch = end($batches);

        if (! $batch instanceof Batch) {
            throw new RuntimeException('The batch repository returned an empty page.');
        }

        $cursor = $batch->id;

        if ($cursor === '' || ($current !== null && strcmp($cursor, $current) >= 0)) {
            throw new RuntimeException('The batch repository did not advance its pagination cursor.');
        }

        return $cursor;
    }

    private function timestamp(?DateTimeInterface $value): ?int
    {
        return $value?->getTimestamp();
    }
}
