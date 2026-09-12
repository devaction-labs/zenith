<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use DevactionLabs\Zenith\Jobs\Data\JobDetailData;
use DevactionLabs\Zenith\Jobs\Data\JobFilterCatalogData;
use DevactionLabs\Zenith\Jobs\Data\JobIndexFiltersData;
use DevactionLabs\Zenith\Jobs\Data\JobPageData;
use DevactionLabs\Zenith\Jobs\Data\JobRowData;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use JsonException;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

final readonly class JobsData
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private JobRepository $jobs,
        private ?RedisFactory $redis = null,
        private ?RetainedJobQuery $retainedQuery = null,
        private ?RetainedJobFilterCatalog $filterCatalog = null,
    ) {}

    public function page(
        JobListType $type,
        int|string|null $afterIndex,
        ?JobIndexFiltersData $filters = null,
        ?string $search = null,
    ): JobPageData {
        $filters ??= JobIndexFiltersData::none();
        $search = $this->normalizedSearch($search);

        if ($this->retainedQuery !== null) {
            try {
                $page = $this->retainedQuery->page(
                    RetainedJobType::fromJobListType($type),
                    $filters,
                    $afterIndex,
                    search: $search,
                );

                return $this->pageData(
                    $page->jobs,
                    $page->total,
                    $page->current,
                    $page->next,
                );
            } catch (Throwable $exception) {
                report($exception);

                return $this->unavailablePage(
                    $afterIndex,
                    $this->unavailableMessage(
                        $type,
                        $filters->hasAny() || $search !== null,
                    ),
                );
            }
        }

        if ($filters->hasAny() || $search !== null) {
            return $this->unavailablePage(
                $afterIndex,
                $this->unavailableMessage($type, filtered: true),
            );
        }

        try {
            $page = $this->retainedPage($type, $afterIndex);
            $jobs = $page['jobs'];
            $total = match ($type) {
                JobListType::Pending => $this->jobs->countPending(),
                JobListType::Completed => $this->jobs->countCompleted(),
                JobListType::Silenced => $this->jobs->countSilenced(),
            };

            return $this->pageData(
                $jobs,
                $total,
                $page['current'],
                $page['next'],
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->unavailablePage(
                $afterIndex,
                $this->unavailableMessage($type, filtered: false),
            );
        }
    }

    private function unavailableMessage(JobListType $type, bool $filtered): string
    {
        if ($filtered) {
            return 'Global job search and filters are currently unavailable.';
        }

        return match ($type) {
            JobListType::Pending => 'Horizon could not read retained pending jobs. Refresh the page to try again.',
            JobListType::Completed => 'Horizon could not read retained completed jobs. Refresh the page to try again.',
            JobListType::Silenced => 'Horizon could not read retained silenced jobs. Refresh the page to try again.',
        };
    }

    public function filters(JobListType $type): JobFilterCatalogData
    {
        if ($this->filterCatalog === null) {
            return JobFilterCatalogData::unavailable();
        }

        try {
            return $this->filterCatalog->for(RetainedJobType::fromJobListType($type));
        } catch (Throwable $exception) {
            report($exception);

            return JobFilterCatalogData::unavailable();
        }
    }

    public function querySignature(
        JobListType $type,
        JobIndexFiltersData $filters,
        ?string $search = null,
    ): string {
        $search = $this->normalizedSearch($search);

        if ($this->retainedQuery !== null) {
            return $this->retainedQuery->signature(
                RetainedJobType::fromJobListType($type),
                $filters,
                search: $search,
            );
        }

        return hash('sha256', json_encode([
            'type' => $type->value,
            'filters' => $filters->signatureValues(),
            'search' => $search,
        ], JSON_THROW_ON_ERROR));
    }

    private function normalizedSearch(?string $search): ?string
    {
        $search = (string) Str::of($search ?? '')->trim();

        return $search === '' ? null : $search;
    }

    public function find(string $id): ?JobDetailData
    {
        try {
            $job = $this->jobs->getJobs([$id])->first();

            return is_object($job) ? $this->detail($job) : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function batchId(object $job): ?string
    {
        $payload = $this->decodePayload($job->payload ?? null);

        return $this->batchIdFromPayload($payload);
    }

    /** @param  array<string, mixed>  $payload */
    public function batchIdFromPayload(array $payload): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $batchId = $data['batchId'] ?? null;

        return is_string($batchId) && $batchId !== '' ? $batchId : null;
    }

    /**
     * Normalize a live Redis queue payload into a safe list row without loading
     * Horizon job hashes or unserializing application classes for display.
     *
     * @param  array{
     *     id: string,
     *     connection: string,
     *     queue: string,
     *     payload: array<string, mixed>,
     *     score: float|null
     * }  $entry
     * @param  'ready'|'reserved'|'delayed'|'released'  $state
     */
    public function rowFromQueueEntry(array $entry, string $state, int $index = 0): ?JobRowData
    {
        $payload = $entry['payload'];
        $id = $entry['id'];
        $name = $this->payloadDisplayName($payload);

        if ($id === '' || $name === null) {
            return null;
        }

        $status = $state === 'reserved' ? 'reserved' : 'pending';
        $pushedAt = $this->timestamp($payload['pushedAt'] ?? null);
        $decodedCommand = $this->decodedCommand($payload);
        $delay = $this->delaySeconds($payload['delay'] ?? null, $decodedCommand, $pushedAt);
        $originalScheduledAt = $this->initialScheduledAt(
            $payload,
            $decodedCommand,
            $pushedAt,
            $delay,
        );
        $score = $this->timestamp($entry['score'] ?? null);
        $scheduledAt = match ($state) {
            'delayed', 'released' => $score ?? $originalScheduledAt,
            'ready', 'reserved' => null,
        };

        return new JobRowData(
            id: $id,
            index: $index,
            name: $name,
            shortName: Str::afterLast($name, '\\'),
            connection: $entry['connection'] !== '' ? $entry['connection'] : 'default',
            queue: $entry['queue'] !== '' ? $entry['queue'] : 'default',
            status: $status,
            tags: $this->tags($payload),
            attempts: is_numeric($payload['attempts'] ?? null) ? (int) $payload['attempts'] : 0,
            retryOf: is_string($payload['retry_of'] ?? null) ? $payload['retry_of'] : null,
            delay: $delay,
            scheduledAt: $scheduledAt,
            originalScheduledAt: $originalScheduledAt,
            pushedAt: $pushedAt,
            reservedAt: null,
            completedAt: null,
            failedAt: null,
            runtime: null,
            occurredAt: $pushedAt,
            retried: false,
            retryCompleted: false,
            retryCount: 0,
            latestRetryStatus: null,
            retryEligible: false,
            attemptsComplete: true,
            inspectable: false,
        );
    }

    public function row(
        object $job,
        bool $retried = false,
        bool $retryCompleted = false,
        int $retryCount = 0,
        ?string $latestRetryStatus = null,
        bool $retryEligible = false,
        ?int $attemptsOverride = null,
        bool $attemptsComplete = true,
    ): ?JobRowData {
        $id = $job->id ?? null;
        $name = $job->name ?? null;

        if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
            return null;
        }

        $payload = $this->decodePayload($job->payload ?? null);
        $pushedAt = $this->timestamp($payload['pushedAt'] ?? null);
        $decodedCommand = $this->decodedCommand($payload);
        $status = is_string($job->status ?? null) ? $job->status : 'unknown';
        $reservedAt = $this->timestamp($job->reserved_at ?? null);
        $completedAt = $status === 'completed'
            ? $this->timestamp($job->completed_at ?? null)
            : null;
        $failedAt = $status === 'failed'
            ? $this->timestamp($job->failed_at ?? null)
            : null;
        $delay = $this->delaySeconds($job->delay ?? null, $decodedCommand, $pushedAt);
        $originalScheduledAt = $this->initialScheduledAt(
            $payload,
            $decodedCommand,
            $pushedAt,
            $delay,
        );
        $scheduledAt = $this->scheduledAt(
            $job,
            $status,
            $payload,
            $originalScheduledAt,
        );
        $finishedAt = match ($status) {
            'failed' => $failedAt,
            'completed' => $completedAt,
            'reserved' => (float) Date::now()->format('U.u'),
            default => null,
        };
        $runtime = $this->runtime($reservedAt, $finishedAt);

        return new JobRowData(
            id: $id,
            index: is_numeric($job->index ?? null) ? (int) $job->index : 0,
            name: $name,
            shortName: Str::afterLast($name, '\\'),
            connection: is_string($job->connection ?? null) ? $job->connection : 'default',
            queue: is_string($job->queue ?? null) ? $job->queue : 'default',
            status: $status,
            tags: $this->tags($payload),
            attempts: $attemptsOverride
                ?? (is_numeric($payload['attempts'] ?? null) ? (int) $payload['attempts'] : 0),
            retryOf: is_string($payload['retry_of'] ?? null) ? $payload['retry_of'] : null,
            delay: $delay,
            scheduledAt: $scheduledAt,
            originalScheduledAt: $originalScheduledAt,
            pushedAt: $pushedAt,
            reservedAt: $reservedAt,
            completedAt: $completedAt,
            failedAt: $failedAt,
            runtime: $runtime,
            occurredAt: $failedAt ?? $completedAt ?? $reservedAt ?? $pushedAt,
            retried: $retried,
            retryCompleted: $retryCompleted,
            retryCount: $retryCount,
            latestRetryStatus: $latestRetryStatus,
            retryEligible: $retryEligible,
            attemptsComplete: $attemptsComplete,
            inspectable: true,
        );
    }

    /** @param  array<string, mixed>  $payload */
    private function payloadDisplayName(array $payload): ?string
    {
        $displayName = $payload['displayName'] ?? null;

        if (is_string($displayName) && $displayName !== '') {
            return $displayName;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $commandName = $data['commandName'] ?? null;

        return is_string($commandName) && $commandName !== '' ? $commandName : null;
    }

    public function detail(object $job): ?JobDetailData
    {
        $row = $this->row($job);

        if ($row === null) {
            return null;
        }

        $payload = $this->decodePayload($job->payload ?? null);
        $decodedCommand = $this->decodedCommand($payload);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return new JobDetailData(
            id: $row->id,
            name: $row->name,
            shortName: $row->shortName,
            connection: $row->connection,
            queue: $row->queue,
            status: $row->status,
            tags: $row->tags,
            attempts: $row->attempts,
            retryOf: $row->retryOf,
            delay: $row->delay,
            scheduledAt: $row->scheduledAt,
            originalScheduledAt: $row->originalScheduledAt,
            batchId: is_string($data['batchId'] ?? null) ? $data['batchId'] : null,
            pushedAt: $row->pushedAt,
            reservedAt: $row->reservedAt,
            completedAt: $row->completedAt,
            failedAt: $row->failedAt,
            runtime: $row->runtime,
            payload: $this->safePayload($payload, $decodedCommand),
        );
    }

    /** @param Collection<int, mixed> $jobs */
    private function lastIndex(Collection $jobs): ?int
    {
        $last = $jobs->last();

        return is_object($last) && is_numeric($last->index ?? null) ? (int) $last->index : null;
    }

    /**
     * @return array{
     *     jobs: Collection<int, mixed>,
     *     current: int|string|null,
     *     next: int|string|null
     * }
     */
    private function retainedPage(JobListType $type, int|string|null $afterIndex): array
    {
        $numericAfterIndex = $this->numericCursor($afterIndex);

        if ($this->redis === null) {
            $cursor = (string) $numericAfterIndex;
            $jobs = match ($type) {
                JobListType::Pending => $this->jobs->getPending($cursor),
                JobListType::Completed => $this->jobs->getCompleted($cursor),
                JobListType::Silenced => $this->jobs->getSilenced($cursor),
            };

            return [
                'jobs' => $jobs,
                'current' => $numericAfterIndex,
                'next' => $jobs->count() === self::PAGE_SIZE ? $this->lastIndex($jobs) : null,
            ];
        }

        $start = $numericAfterIndex + 1;
        $key = match ($type) {
            JobListType::Pending => 'pending_jobs',
            JobListType::Completed => 'completed_jobs',
            JobListType::Silenced => 'silenced_jobs',
        };
        $method = RetainedJobType::fromJobListType($type)->newestFirst()
            ? 'zrange'
            : 'zrevrange';
        $ids = $this->redis->connection('horizon')->{$method}(
            $key,
            $start,
            $start + self::PAGE_SIZE,
        );

        if (! is_array($ids)) {
            return [
                'jobs' => new Collection,
                'current' => $numericAfterIndex,
                'next' => null,
            ];
        }

        $hasMore = count($ids) > self::PAGE_SIZE;
        $ids = array_slice($ids, 0, self::PAGE_SIZE);

        return [
            'jobs' => $this->jobs->getJobs(
                array_values(array_filter($ids, is_string(...))),
                $start,
            ),
            'current' => $numericAfterIndex,
            'next' => $hasMore ? $start + self::PAGE_SIZE - 1 : null,
        ];
    }

    private function numericCursor(int|string|null $cursor): int
    {
        return is_numeric($cursor) ? (int) $cursor : -1;
    }

    /** @param Collection<int, mixed> $jobs */
    private function pageData(
        Collection $jobs,
        int $total,
        int|string|null $current,
        int|string|null $next,
    ): JobPageData {
        $items = [];

        foreach ($jobs as $job) {
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

    private function unavailablePage(
        int|string|null $current,
        string $message,
    ): JobPageData {
        return new JobPageData(
            available: false,
            items: [],
            total: 0,
            current: $current,
            next: null,
            message: $message,
        );
    }

    /** @return array<string, mixed> */
    private function decodePayload(mixed $payload): array
    {
        if (! is_string($payload) || $payload === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function tags(array $payload): array
    {
        $tags = $payload['tags'] ?? [];

        return is_array($tags)
            ? array_values(array_filter($tags, is_string(...)))
            : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<array-key, mixed>|null  $decodedCommand
     * @return array<string, mixed>
     */
    private function safePayload(array $payload, ?array $decodedCommand): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        unset($data['command']);

        if ($decodedCommand !== null) {
            $data['decodedCommand'] = $decodedCommand;
        }

        return array_filter([
            'displayName' => is_string($payload['displayName'] ?? null) ? $payload['displayName'] : null,
            'job' => is_string($payload['job'] ?? null) ? $payload['job'] : null,
            'uuid' => is_string($payload['uuid'] ?? null) ? $payload['uuid'] : null,
            'maxTries' => is_numeric($payload['maxTries'] ?? null) ? (int) $payload['maxTries'] : null,
            'timeout' => is_numeric($payload['timeout'] ?? null) ? (int) $payload['timeout'] : null,
            'data' => $data,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function timestamp(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Decode Horizon's serialized command without instantiating application classes.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>|null
     */
    private function decodedCommand(array $payload): ?array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $serialized = $data['command'] ?? null;

        if (! is_string($serialized) || $serialized === '') {
            return null;
        }

        try {
            $command = @unserialize($serialized, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }

        $normalized = $this->normalizeCommandValue($command);

        return is_array($normalized) ? $normalized : null;
    }

    private function normalizeCommandValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 8) {
            return '[Maximum depth reached]';
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];

            foreach (array_slice($value, 0, 200, true) as $key => $item) {
                $normalized[$key] = $this->normalizeCommandValue($item, $depth + 1);
            }

            return $normalized;
        }

        if (! is_object($value)) {
            return null;
        }

        $normalized = [];

        foreach (array_slice(get_object_vars($value), 0, 200, true) as $key => $item) {
            $normalized[$this->normalizeCommandKey($key)] = $this->normalizeCommandValue(
                $item,
                $depth + 1,
            );
        }

        return $normalized;
    }

    private function normalizeCommandKey(int|string $key): int|string
    {
        if (! is_string($key)) {
            return $key;
        }

        if ($key === '__PHP_Incomplete_Class_Name') {
            return 'class';
        }

        $segments = explode("\0", $key);

        return end($segments) ?: $key;
    }

    /** @param array<array-key, mixed>|null $decodedCommand */
    private function initialDelayedUntil(
        ?array $decodedCommand,
        ?float $pushedAt,
        ?int $delay,
    ): ?float {
        $commandDelay = $decodedCommand['delay'] ?? null;

        if (is_array($commandDelay) && is_string($commandDelay['date'] ?? null)) {
            try {
                $timezone = is_string($commandDelay['timezone'] ?? null)
                    ? new DateTimeZone($commandDelay['timezone'])
                    : null;
                $date = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s.u',
                    $commandDelay['date'],
                    $timezone,
                );

                if ($date !== false) {
                    return (float) $date->getTimestamp();
                }
            } catch (Throwable) {
                return null;
            }
        }

        if ($pushedAt === null || $delay === null) {
            return null;
        }

        return $pushedAt + $delay;
    }

    /** @param array<array-key, mixed>|null $decodedCommand */
    private function delaySeconds(mixed $jobDelay, ?array $decodedCommand, ?float $pushedAt): ?int
    {
        $storedDelay = $this->numericDelay($jobDelay);

        if ($storedDelay !== null) {
            return $storedDelay;
        }

        $commandDelay = $decodedCommand['delay'] ?? null;

        if (is_numeric($commandDelay)) {
            return max(0, (int) $commandDelay);
        }

        $delayedUntil = $this->initialDelayedUntil($decodedCommand, $pushedAt, null);

        return $delayedUntil !== null && $pushedAt !== null
            ? max(0, (int) round($delayedUntil - $pushedAt))
            : null;
    }

    private function numericDelay(mixed $delay): ?int
    {
        return is_numeric($delay) ? max(0, (int) $delay) : null;
    }

    /** @param array<string, mixed> $payload */
    private function scheduledAt(
        object $job,
        string $status,
        array $payload,
        ?float $originalScheduledAt,
    ): ?float {
        if ($status !== 'pending') {
            return null;
        }

        if (is_numeric($payload['zenith']['madeAvailableAt'] ?? null)) {
            return null;
        }

        $releasedDelay = $this->numericDelay($job->delay ?? null);

        if ($releasedDelay === null) {
            return $originalScheduledAt;
        }

        if ($releasedDelay > 0) {
            return $this->releasedUntil($job, $releasedDelay);
        }

        return $originalScheduledAt;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<array-key, mixed>|null  $decodedCommand
     */
    private function initialScheduledAt(
        array $payload,
        ?array $decodedCommand,
        ?float $pushedAt,
        ?int $delay,
    ): ?float {
        if (isset($payload['retry_of'])) {
            return null;
        }

        $createdAt = $this->timestamp($payload['createdAt'] ?? null);
        $payloadDelay = $this->numericDelay($payload['delay'] ?? null);

        if ($createdAt !== null && $payloadDelay !== null && $payloadDelay > 0) {
            return $createdAt + $payloadDelay;
        }

        $commandDelay = $decodedCommand['delay'] ?? null;

        if (is_numeric($commandDelay) && $pushedAt !== null && (float) $commandDelay > 0) {
            return $pushedAt + (float) $commandDelay;
        }

        return $this->initialDelayedUntil($decodedCommand, $pushedAt, $delay);
    }

    private function releasedUntil(object $job, int $delay): ?float
    {
        $updatedAt = $this->timestamp($job->updated_at ?? null)
            ?? $this->storedUpdatedAt($job->id ?? null);

        return $updatedAt === null ? null : $updatedAt + $delay;
    }

    private function storedUpdatedAt(mixed $id): ?float
    {
        if (! is_string($id) || $id === '' || $this->redis === null) {
            return null;
        }

        try {
            return $this->timestamp(
                $this->redis->connection('horizon')->hget($id, 'updated_at'),
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function runtime(?float $reservedAt, ?float $finishedAt): ?float
    {
        return $reservedAt !== null && $finishedAt !== null
            ? round(max(0, $finishedAt - $reservedAt), 3)
            : null;
    }
}
