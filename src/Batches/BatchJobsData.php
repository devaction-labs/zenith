<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches;

use DevactionLabs\Zenith\Batches\Data\BatchJobListData;
use DevactionLabs\Zenith\Batches\Data\BatchJobListsData;
use DevactionLabs\Zenith\Jobs\Data\JobRowData;
use DevactionLabs\Zenith\Jobs\JobListType;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Jobs\PendingJobEntryScanner;
use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use LogicException;
use Throwable;

final readonly class BatchJobsData
{
    private const int PAGE_SIZE = 50;

    private BatchFailedJobLineages $failedJobLineages;

    public function __construct(
        private JobRepository $jobs,
        private JobsData $jobData,
        private ?PendingJobEntryScanner $pendingStates = null,
        ?BatchFailedJobLineages $failedJobLineages = null,
    ) {
        $this->failedJobLineages = $failedJobLineages ?? new BatchFailedJobLineages($jobData);
    }

    public function forBatch(
        Batch $batch,
        ?DatabaseBatchMetadata $attribution = null,
    ): BatchJobListsData {
        $failed = min(max(0, $batch->pendingJobs), max(0, $batch->failedJobs));
        $pending = max(0, $batch->pendingJobs - $failed);
        $completed = max(0, $batch->processedJobs());

        return new BatchJobListsData(
            pending: $this->pending($batch, $pending, $attribution),
            completed: $this->retained($batch->id, $completed, JobListType::Completed),
            failed: $this->failed($batch),
        );
    }

    private function pending(
        Batch $batch,
        int $total,
        ?DatabaseBatchMetadata $attribution,
    ): BatchJobListData {
        if ($total <= 0) {
            return $this->empty($total);
        }

        if ($this->pendingStates !== null) {
            try {
                return $this->pendingFromLiveQueue($batch, $total, $attribution);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $this->pendingFromRetainedScan($batch, $total);
    }

    private function pendingFromLiveQueue(
        Batch $batch,
        int $total,
        ?DatabaseBatchMetadata $attribution,
    ): BatchJobListData {
        if ($this->pendingStates === null) {
            throw new LogicException('Pending queue scanning is unavailable.');
        }

        $entries = [];
        $seen = [];

        foreach ($this->pendingStates->pendingQueueEntries($this->queueTarget($batch, $attribution)) as $entry) {
            if (isset($seen[$entry['id']])) {
                continue;
            }

            if ($this->jobData->batchIdFromPayload($entry['payload']) !== $batch->id) {
                continue;
            }

            $seen[$entry['id']] = true;
            $entries[] = $entry;

            if (count($entries) >= $total) {
                break;
            }
        }

        $ids = array_map(
            static fn (array $entry): string => $entry['id'],
            $entries,
        );
        $retainedById = $this->hydrateRetainedPendingRows($ids);
        $rows = [];
        $index = 0;

        foreach ($entries as $entry) {
            $retained = $retainedById[$entry['id']] ?? null;

            if (
                $retained !== null
                && in_array($retained->status, ['pending', 'reserved'], true)
            ) {
                $rows[] = $retained;
            } else {
                $row = $this->jobData->rowFromQueueEntry($entry, $entry['state'], $index);

                if ($row === null) {
                    continue;
                }

                $rows[] = $row;
            }

            $index++;
        }

        $complete = count($rows) >= $total;

        return new BatchJobListData(
            total: $total,
            rows: $rows,
            available: true,
            complete: $complete,
            message: $complete
                ? null
                : 'Some pending jobs could not be found in Horizon retention or the live queue.',
        );
    }

    private function pendingFromRetainedScan(Batch $batch, int $total): BatchJobListData
    {
        try {
            $retained = $this->scanRetained($batch->id, $total, JobListType::Pending);

            return $this->result(
                total: $total,
                rows: $retained,
                incompleteMessage: 'Some pending jobs are no longer retained by Horizon.',
            );
        } catch (Throwable $exception) {
            report($exception);

            return new BatchJobListData(
                total: $total,
                rows: [],
                available: false,
                complete: false,
                message: 'Pending jobs for this batch are currently unavailable.',
            );
        }
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, JobRowData>
     */
    private function hydrateRetainedPendingRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $retainedById = [];

        foreach ($this->jobs->getJobs($ids) as $job) {
            if (! is_object($job)) {
                continue;
            }

            $row = $this->jobData->row($job);

            if ($row !== null) {
                $retainedById[$row->id] = $row;
            }
        }

        return $retainedById;
    }

    private function retained(string $batchId, int $total, JobListType $type): BatchJobListData
    {
        if ($total <= 0) {
            return $this->empty($total);
        }

        try {
            $rows = $this->scanRetained($batchId, $total, $type);

            return $this->result(
                total: $total,
                rows: $rows,
                incompleteMessage: "Some {$type->value} jobs are no longer retained by Horizon.",
            );
        } catch (Throwable $exception) {
            report($exception);

            return new BatchJobListData(
                total: $total,
                rows: [],
                available: false,
                complete: false,
                message: ucfirst($type->value).' jobs for this batch are currently unavailable.',
            );
        }
    }

    /**
     * @return list<JobRowData>
     */
    private function scanRetained(string $batchId, int $total, JobListType $type): array
    {
        $rows = [];
        $cursor = null;
        $visitedCursors = [];

        while (count($rows) < $total) {
            $page = match ($type) {
                JobListType::Pending => $this->jobs->getPending($cursor),
                JobListType::Completed => $this->jobs->getCompleted($cursor),
                JobListType::Silenced => throw new LogicException('Batch job history does not scan silenced jobs.'),
            };
            $page = collect($page);

            foreach ($page as $job) {
                if (count($rows) >= $total) {
                    break;
                }

                if (! is_object($job) || $this->jobData->batchId($job) !== $batchId) {
                    continue;
                }

                $row = $this->jobData->row($job);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            if (count($rows) >= $total || $page->count() < self::PAGE_SIZE) {
                break;
            }

            $next = $this->lastIndex($page);

            if ($next === null || $next === $cursor || isset($visitedCursors[$next])) {
                break;
            }

            $visitedCursors[$next] = true;
            $cursor = $next;
        }

        return $rows;
    }

    /**
     * Resolve the same queue target shown on the batch detail page:
     * stored sidecar metadata, then explicit batch options, then configured defaults.
     *
     * @return array{connection: string, queue: string}
     */
    private function queueTarget(
        Batch $batch,
        ?DatabaseBatchMetadata $attribution,
    ): array {
        $metadata = $attribution
            ?? DatabaseBatchMetadata::fromOptions(
                $batch->id,
                array_filter($batch->options, is_string(...), ARRAY_FILTER_USE_KEY),
            );

        $connection = $metadata->connection;

        if ($connection === null) {
            $configuredDefault = config('queue.default');
            $connection = is_string($configuredDefault) && $configuredDefault !== ''
                ? $configuredDefault
                : 'redis';
        }

        $queue = $metadata->queue
            ?? $this->configuredQueue($connection)
            ?? 'default';

        return [
            'connection' => $connection,
            'queue' => $queue,
        ];
    }

    private function configuredQueue(string $connection): ?string
    {
        $connections = config('queue.connections', []);

        if (! is_array($connections) || ! is_array($connections[$connection] ?? null)) {
            return null;
        }

        $queue = $connections[$connection]['queue'] ?? null;

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    private function failed(Batch $batch): BatchJobListData
    {
        $total = min(max(0, $batch->pendingJobs), max(0, $batch->failedJobs));

        if ($total === 0) {
            return $this->empty(0);
        }

        $ids = array_values(array_unique(array_filter(
            $batch->failedJobIds,
            static fn (mixed $id): bool => is_string($id) && trim($id) !== '',
        )));

        if ($ids === []) {
            return $this->result(
                total: $total,
                rows: [],
                incompleteMessage: 'Some failed jobs are no longer retained by Horizon.',
            );
        }

        try {
            $retainedJobs = [];

            foreach ($this->jobs->getJobs($ids) as $job) {
                if (! is_object($job)) {
                    continue;
                }

                $retainedJobs[] = $job;
            }

            $summary = $this->failedJobLineages->summarize($retainedJobs, $ids);
            $rows = array_slice($summary['rows'], 0, $total);
            $complete = $summary['complete'] && count($rows) >= $total;

            return new BatchJobListData(
                total: $total,
                rows: $rows,
                available: true,
                complete: $complete,
                message: $complete ? null : 'Some failed jobs are no longer retained by Horizon.',
            );
        } catch (Throwable $exception) {
            report($exception);

            return new BatchJobListData(
                total: $total,
                rows: [],
                available: false,
                complete: false,
                message: 'Failed jobs for this batch are currently unavailable.',
            );
        }
    }

    private function empty(int $total): BatchJobListData
    {
        return new BatchJobListData(
            total: max(0, $total),
            rows: [],
            available: true,
            complete: true,
            message: null,
        );
    }

    /**
     * @param  array<int, JobRowData>  $rows
     */
    private function result(int $total, array $rows, string $incompleteMessage): BatchJobListData
    {
        $complete = count($rows) >= $total;

        return new BatchJobListData(
            total: $total,
            rows: $rows,
            available: true,
            complete: $complete,
            message: $complete ? null : $incompleteMessage,
        );
    }

    /** @param Collection<int, mixed> $jobs */
    private function lastIndex(Collection $jobs): ?string
    {
        $last = $jobs->last();

        return is_object($last) && is_numeric($last->index ?? null)
            ? (string) $last->index
            : null;
    }
}
