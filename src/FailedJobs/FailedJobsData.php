<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\FailedJobs;

use DevactionLabs\Zenith\FailedJobs\Data\FailedJobBulkActionsData;
use DevactionLabs\Zenith\FailedJobs\Data\FailedJobDetailData;
use DevactionLabs\Zenith\FailedJobs\Data\FailedJobRetryData;
use DevactionLabs\Zenith\Jobs\Data\JobFilterCatalogData;
use DevactionLabs\Zenith\Jobs\Data\JobIndexFiltersData;
use DevactionLabs\Zenith\Jobs\Data\JobPageData;
use DevactionLabs\Zenith\Jobs\Data\JobRowData;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Jobs\RetainedJobFilterCatalog;
use DevactionLabs\Zenith\Jobs\RetainedJobQuery;
use DevactionLabs\Zenith\Jobs\RetainedJobType;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Collection;
use JsonException;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Predis\ClientContextInterface;
use Redis;
use Throwable;

final readonly class FailedJobsData
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private JobRepository $repository,
        private TagRepository $tags,
        private JobsData $jobs,
        private FailedJobRetryEligibility $retryEligibility,
        private ?RedisFactory $redis = null,
        private ?RetainedJobQuery $retainedQuery = null,
        private ?RetainedJobFilterCatalog $filterCatalog = null,
    ) {}

    public function page(
        int|string|null $afterIndex,
        ?string $tag = null,
        ?JobIndexFiltersData $filters = null,
    ): JobPageData {
        $filters ??= JobIndexFiltersData::none();

        if ($this->retainedQuery !== null && $filters->hasAny()) {
            try {
                $page = $this->retainedQuery->page(
                    RetainedJobType::Failed,
                    $filters,
                    $afterIndex,
                    $tag,
                );

                return $this->pageData(
                    $page->jobs,
                    $page->total,
                    $page->current,
                    $page->next,
                );
            } catch (Throwable $exception) {
                report($exception);

                return new JobPageData(
                    available: false,
                    items: [],
                    total: 0,
                    current: $afterIndex,
                    next: null,
                    message: 'Global failed-job filters are currently unavailable.',
                );
            }
        }

        try {
            $numericAfterIndex = is_numeric($afterIndex)
                ? (int) $afterIndex
                : -1;
            $tag = trim($tag ?? '');

            if ($tag === '') {
                $page = $this->oldestFailed($numericAfterIndex);
                $failed = $page['jobs'];
                $total = $this->repository->countFailed();
                $current = $numericAfterIndex;
                $next = $page['next'];
            } else {
                $current = max(0, $numericAfterIndex);
                $ids = $this->oldestTaggedFailed($tag, $current);
                $hasMore = count($ids) > self::PAGE_SIZE;
                $jobIds = array_slice($ids, 0, self::PAGE_SIZE)
                        |> (static fn ($x) => array_filter($x, is_string(...)))
                        |> array_values(...);
                $failed = $this->repository->getJobs($jobIds, $current);
                $total = $this->tags->count("failed:{$tag}");
                $next = $hasMore ? $current + self::PAGE_SIZE : null;
            }

            return $this->pageData($failed, $total, $current, $next);
        } catch (Throwable $exception) {
            report($exception);

            return new JobPageData(
                available: false,
                items: [],
                total: 0,
                current: $afterIndex,
                next: null,
                message: 'Failed jobs are currently unavailable.',
            );
        }
    }

    public function filters(): JobFilterCatalogData
    {
        if ($this->filterCatalog === null) {
            return JobFilterCatalogData::unavailable();
        }

        try {
            return $this->filterCatalog->for(RetainedJobType::Failed);
        } catch (Throwable $exception) {
            report($exception);

            return JobFilterCatalogData::unavailable();
        }
    }

    /**
     * @throws JsonException
     */
    public function querySignature(
        JobIndexFiltersData $filters,
        ?string $tag,
    ): string {
        if ($this->retainedQuery !== null) {
            return $this->retainedQuery->signature(
                RetainedJobType::Failed,
                $filters,
                $tag,
            );
        }

        return hash('sha256', json_encode([
            'type' => RetainedJobType::Failed->value,
            'filters' => $filters->signatureValues(),
            'tag' => $tag,
        ], JSON_THROW_ON_ERROR));
    }

    public function hasRetryable(): bool
    {
        try {
            return $this->hasRetryableJob();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function bulkActions(): FailedJobBulkActionsData
    {
        try {
            $sourceTotal = max(0, (int) $this->repository->countFailed());

            if ($sourceTotal === 0) {
                return new FailedJobBulkActionsData(
                    hasFailedJobs: false,
                    retryable: false,
                    retryUnavailableReason: null,
                    clearable: false,
                    clearUnavailableReason: null,
                );
            }

            $retryable = $this->hasRetryableJob();

            return new FailedJobBulkActionsData(
                hasFailedJobs: true,
                retryable: $retryable,
                retryUnavailableReason: $retryable
                    ? null
                    : 'No retained failed jobs are eligible for bulk retry.',
                clearable: true,
                clearUnavailableReason: null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return new FailedJobBulkActionsData(
                hasFailedJobs: false,
                retryable: false,
                retryUnavailableReason: 'Failed-job bulk actions are currently unavailable.',
                clearable: false,
                clearUnavailableReason: 'Failed-job bulk actions are currently unavailable.',
            );
        }
    }

    public function row(object $job): ?JobRowData
    {
        $retries = $this->retries($job);

        return $this->jobs->row(
            $job,
            retried: $retries !== [],
            retryCompleted: $this->hasCompletedRetry($retries),
            retryCount: count($retries),
            latestRetryStatus: $retries[0]->status ?? null,
            retryEligible: $this->retryEligibility->allows($job),
        );
    }

    public function find(string $id): ?FailedJobDetailData
    {
        try {
            $job = $this->repository->findFailed($id);

            if (! is_object($job) || ($job->status ?? null) !== 'failed') {
                return null;
            }

            $detail = $this->jobs->detail($job);

            if ($detail === null) {
                return null;
            }

            return new FailedJobDetailData(
                id: $detail->id,
                name: $detail->name,
                shortName: $detail->shortName,
                connection: $detail->connection,
                queue: $detail->queue,
                status: $detail->status,
                tags: $detail->tags,
                pushedAt: $detail->pushedAt,
                reservedAt: $detail->reservedAt,
                failedAt: $detail->failedAt,
                runtime: $detail->runtime,
                attempts: $detail->attempts,
                retryOf: $detail->retryOf,
                delay: $detail->delay,
                scheduledAt: $detail->scheduledAt,
                originalScheduledAt: $detail->originalScheduledAt,
                batchId: $detail->batchId,
                retried: $this->wasRetried($job),
                retriedBy: $this->retries($job),
                retryEligible: $this->retryEligibility->allows($job),
                payload: $detail->payload,
                context: $this->context($job->context ?? null),
                exception: is_string($job->exception ?? null)
                    ? mb_convert_encoding($job->exception, 'UTF-8', 'UTF-8')
                    : '',
                composition: $detail->composition,
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array{jobs: Collection<int, mixed>, next: int|null}
     */
    private function oldestFailed(int $afterIndex): array
    {
        if ($this->redis === null) {
            $failed = $this->repository->getFailed((string) $afterIndex);

            return [
                'jobs' => $failed,
                'next' => $failed->count() === self::PAGE_SIZE ? $this->lastIndex($failed) : null,
            ];
        }

        $start = $afterIndex + 1;
        $ids = $this->redis->connection('horizon')->zrevrange(
            'failed_jobs',
            $start,
            $start + self::PAGE_SIZE,
        );

        if (! is_array($ids)) {
            return ['jobs' => new Collection, 'next' => null];
        }

        $hasMore = count($ids) > self::PAGE_SIZE;
        $ids = array_slice($ids, 0, self::PAGE_SIZE);

        return [
            'jobs' => array_filter($ids, is_string(...))
                    |> array_values(...)
                    |> (fn ($x) => $this->repository->getJobs($x, $start)),
            'next' => $hasMore ? $start + self::PAGE_SIZE - 1 : null,
        ];
    }

    /**
     * @template TJob
     *
     * @param  Collection<int, TJob>  $failed
     */
    private function pageData(
        Collection $failed,
        int $total,
        int|string|null $current,
        int|string|null $next,
    ): JobPageData {
        $items = [];

        foreach ($failed as $job) {
            if (! is_object($job)) {
                continue;
            }

            $row = $this->row($job);

            if ($row !== null) {
                $items[] = $row;
            }
        }

        return new JobPageData(
            available: true,
            items: $items,
            total: $total,
            current: $current,
            next: $next,
            message: null,
        );
    }

    /** @return array<int, mixed> */
    private function oldestTaggedFailed(string $tag, int $startingAt): array
    {
        if ($this->redis === null) {
            return array_values($this->tags->paginate("failed:{$tag}", $startingAt, self::PAGE_SIZE + 1));
        }

        $ids = $this->redis->connection('horizon')->zrange(
            "failed:{$tag}",
            $startingAt,
            $startingAt + self::PAGE_SIZE,
        );

        return is_array($ids) ? array_values($ids) : [];
    }

    /**
     * @throws Throwable
     */
    private function hasRetryableFromRawIndex(): bool
    {
        $startingAt = 0;
        $connection = $this->redis?->connection('horizon');

        if ($connection === null) {
            return false;
        }

        if (! $connection instanceof PhpRedisConnection && ! $connection instanceof PredisConnection) {
            return false;
        }

        while (true) {
            $ids = $connection->zrevrange(
                'failed_jobs',
                $startingAt,
                $startingAt + self::PAGE_SIZE,
            );

            if (! is_array($ids) || $ids === []) {
                return false;
            }

            $hasMore = count($ids) > self::PAGE_SIZE;
            $pageIds = array_slice($ids, 0, self::PAGE_SIZE)
                    |> (static fn ($x) => array_filter($x, is_string(...)))
                    |> array_values(...);
            $failed = $connection->pipeline(static function (
                Redis|ClientContextInterface|PhpRedisConnection|PredisConnection $pipeline,
            ) use ($pageIds): void {
                foreach ($pageIds as $id) {
                    $pipeline->hmget($id, ['payload', 'retried_by']);
                }
            });

            if (! is_array($failed)) {
                return false;
            }

            foreach ($failed as $job) {
                if (! is_array($job)) {
                    continue;
                }

                $fields = array_values($job);
                $retryCandidate = (object) [
                    'payload' => $fields[0] ?? null,
                    'retried_by' => $fields[1] ?? null,
                ];

                if ($this->retryEligibility->allowsBulk($retryCandidate)) {
                    return true;
                }
            }

            if (! $hasMore) {
                return false;
            }

            $startingAt += self::PAGE_SIZE;
        }
    }

    /**
     * @throws Throwable
     */
    private function hasRetryableJob(): bool
    {
        if ($this->redis !== null) {
            return $this->hasRetryableFromRawIndex();
        }

        $afterIndex = -1;

        while (true) {
            $failed = $this->repository->getFailed((string) $afterIndex);

            if ($failed->isEmpty()) {
                return false;
            }

            foreach ($failed as $job) {
                if (is_object($job) && $this->retryEligibility->allowsBulk($job)) {
                    return true;
                }
            }

            if ($failed->count() < self::PAGE_SIZE) {
                return false;
            }

            $nextIndex = $this->lastIndex($failed);

            if ($nextIndex === null || $nextIndex <= $afterIndex) {
                return false;
            }

            $afterIndex = $nextIndex;
        }
    }

    /**
     * @param  Collection<int, mixed>  $jobs
     */
    private function lastIndex(Collection $jobs): ?int
    {
        $last = $jobs->last();

        return is_object($last) && is_numeric($last->index ?? null) ? (int) $last->index : null;
    }

    private function wasRetried(object $job): bool
    {
        return $this->retries($job) !== [];
    }

    /** @param array<int, FailedJobRetryData> $retries */
    private function hasCompletedRetry(array $retries): bool
    {
        return array_any($retries, fn ($retry) => $retry->status === 'completed');

    }

    /** @return array<int, FailedJobRetryData> */
    private function retries(object $job): array
    {
        $retriedBy = $job->retried_by ?? null;

        if (is_array($retriedBy)) {
            return $this->normalizeRetries($retriedBy);
        }

        if (! is_string($retriedBy) || $retriedBy === '') {
            return [];
        }

        try {
            $decoded = json_decode($retriedBy, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $this->normalizeRetries($decoded) : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param  array<array-key, mixed>  $retries
     * @return array<int, FailedJobRetryData>
     */
    private function normalizeRetries(array $retries): array
    {
        $normalized = [];

        foreach ($retries as $retry) {
            if (! is_array($retry) || ! is_string($retry['id'] ?? null) || $retry['id'] === '') {
                continue;
            }

            $normalized[] = new FailedJobRetryData(
                id: $retry['id'],
                status: is_string($retry['status'] ?? null) ? $retry['status'] : 'unknown',
                retriedAt: is_numeric($retry['retried_at'] ?? null) ? (float) $retry['retried_at'] : null,
            );
        }

        usort(
            $normalized,
            static fn (FailedJobRetryData $left, FailedJobRetryData $right): int => ($right->retriedAt ?? 0.0) <=> ($left->retriedAt ?? 0.0),
        );

        return $normalized;
    }

    /** @return array<array-key, mixed> */
    private function context(mixed $context): array
    {
        if (! is_string($context) || $context === '') {
            return [];
        }

        try {
            $decoded = json_decode($context, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }
}
