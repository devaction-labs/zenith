<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Batches;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use JsonException;
use Laravel\Horizon\Contracts\JobRepository;
use NckRtl\HorizonNewDawn\Batches\Data\BatchClearCountsData;
use NckRtl\HorizonNewDawn\Jobs\JobsData;
use Throwable;

final readonly class ClearableBatches
{
    private const int BATCH_PAGE_SIZE = 1000;

    private const int JOB_PAGE_SIZE = 50;

    private const int FAILED_JOB_CHUNK_SIZE = 500;

    private const int ID_CHUNK_SIZE = 100;

    public function __construct(
        private BatchRepository $batches,
        private JobRepository $jobs,
        private JobsData $jobData,
        private ?DatabaseBatchQuery $databaseQuery = null,
    ) {}

    public function counts(): BatchClearCountsData
    {
        try {
            $databaseQuery = $this->databaseQuery;

            if ($databaseQuery !== null && $databaseQuery->supported()) {
                return $this->databaseCounts($databaseQuery);
            }

            $classification = $this->classifiedRepositoryIds();
        } catch (Throwable $exception) {
            report($exception);

            return new BatchClearCountsData(
                incomplete: 0,
                complete: 0,
                finished: 0,
                cancelled: 0,
                available: false,
                completeScan: false,
                message: 'Batch clearing availability could not be verified.',
            );
        }

        $complete = count($classification[BatchClearScope::Complete->value]);
        $incomplete = count($classification[BatchClearScope::Incomplete->value]);
        $cancelled = count($classification[BatchClearScope::Cancelled->value]);

        return new BatchClearCountsData(
            incomplete: $incomplete,
            complete: $complete,
            finished: $complete + $incomplete,
            cancelled: $cancelled,
            available: true,
            completeScan: true,
            message: null,
        );
    }

    /**
     * Stream clearable batch IDs in bounded chunks for destructive workers.
     *
     * @return \Generator<int, list<string>>
     */
    public function idChunks(
        BatchClearScope $scope,
        int $chunkSize = self::ID_CHUNK_SIZE,
    ): \Generator {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('Chunk size must be a positive integer.');
        }

        $databaseQuery = $this->databaseQuery;

        if ($databaseQuery !== null && $databaseQuery->supported()) {
            yield from $this->databaseIdChunks($databaseQuery, $scope, $chunkSize);

            return;
        }

        $classification = $this->classifiedRepositoryIds();
        $ids = $this->idsForScope($classification, $scope);
        $buffer = [];

        foreach ($ids as $id) {
            $buffer[] = $id;

            if (count($buffer) === $chunkSize) {
                yield $buffer;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            yield $buffer;
        }
    }

    /** @return array<int, string> */
    public function ids(BatchClearScope $scope): array
    {
        $ids = [];

        foreach ($this->idChunks($scope, self::ID_CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function databaseCounts(DatabaseBatchQuery $databaseQuery): BatchClearCountsData
    {
        $complete = 0;
        $incomplete = 0;
        $cancelled = 0;

        foreach ($databaseQuery->clearCandidateChunks() as $candidates) {
            $clearableIds = $this->clearableDatabaseCandidateIds($candidates);

            foreach ($candidates as $candidate) {
                if (! isset($clearableIds[$candidate->id])) {
                    continue;
                }

                match ($candidate->scope) {
                    BatchClearScope::Complete => $complete++,
                    BatchClearScope::Incomplete => $incomplete++,
                    BatchClearScope::Cancelled => $cancelled++,
                    BatchClearScope::Finished => null,
                };
            }
        }

        return new BatchClearCountsData(
            incomplete: $incomplete,
            complete: $complete,
            finished: $complete + $incomplete,
            cancelled: $cancelled,
            available: true,
            completeScan: true,
            message: null,
        );
    }

    /**
     * @return \Generator<int, list<string>>
     */
    private function databaseIdChunks(
        DatabaseBatchQuery $databaseQuery,
        BatchClearScope $scope,
        int $chunkSize,
    ): \Generator {
        $buffer = [];

        foreach ($databaseQuery->clearCandidateChunks() as $candidates) {
            $clearableIds = $this->clearableDatabaseCandidateIds($candidates);

            foreach ($candidates as $candidate) {
                if (! isset($clearableIds[$candidate->id])) {
                    continue;
                }

                if (! $this->scopeMatches($scope, $candidate->scope)) {
                    continue;
                }

                $buffer[] = $candidate->id;

                if (count($buffer) === $chunkSize) {
                    yield $buffer;
                    $buffer = [];
                }
            }
        }

        if ($buffer !== []) {
            yield $buffer;
        }
    }

    private function scopeMatches(BatchClearScope $requested, BatchClearScope $candidate): bool
    {
        return match ($requested) {
            BatchClearScope::Finished => in_array(
                $candidate,
                [BatchClearScope::Complete, BatchClearScope::Incomplete],
                true,
            ),
            default => $requested === $candidate,
        };
    }

    /**
     * @param  array{
     *     complete: array<int, string>,
     *     incomplete: array<int, string>,
     *     cancelled: array<int, string>
     * }  $classification
     * @return array<int, string>
     */
    private function idsForScope(array $classification, BatchClearScope $scope): array
    {
        return match ($scope) {
            BatchClearScope::Incomplete => $classification[BatchClearScope::Incomplete->value],
            BatchClearScope::Complete => $classification[BatchClearScope::Complete->value],
            BatchClearScope::Cancelled => $classification[BatchClearScope::Cancelled->value],
            BatchClearScope::Finished => [
                ...$classification[BatchClearScope::Complete->value],
                ...$classification[BatchClearScope::Incomplete->value],
            ],
        };
    }

    /**
     * @param  list<DatabaseBatchClearCandidate>  $candidates
     * @return array<string, true>
     */
    private function clearableDatabaseCandidateIds(array $candidates): array
    {
        $clearableIds = [];
        $candidateIdsByFailedJobId = [];

        foreach ($candidates as $candidate) {
            if ($candidate->failedJobIds === null) {
                continue;
            }

            $clearableIds[$candidate->id] = true;

            foreach ($candidate->failedJobIds as $failedJobId) {
                $candidateIdsByFailedJobId[$failedJobId][$candidate->id] = true;
            }
        }

        foreach (array_chunk(array_keys($candidateIdsByFailedJobId), self::FAILED_JOB_CHUNK_SIZE) as $failedJobIds) {
            $verifiedParents = $this->verifiedFailedParents($failedJobIds);

            foreach ($failedJobIds as $failedJobId) {
                if (isset($verifiedParents[$failedJobId])) {
                    continue;
                }

                foreach (array_keys($candidateIdsByFailedJobId[$failedJobId]) as $candidateId) {
                    unset($clearableIds[$candidateId]);
                }
            }
        }

        return $clearableIds;
    }

    /**
     * @param  list<string>  $failedJobIds
     * @return array<string, true>
     */
    private function verifiedFailedParents(array $failedJobIds): array
    {
        try {
            $jobs = $this->jobs->getJobs($failedJobIds);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $requested = array_fill_keys($failedJobIds, true);
        $verified = [];
        $seen = [];
        $duplicates = [];

        foreach ($jobs as $job) {
            $id = is_object($job) && is_string($job->id ?? null)
                ? $job->id
                : null;

            if ($id === null || ! isset($requested[$id])) {
                continue;
            }

            if (isset($seen[$id])) {
                $duplicates[$id] = true;

                continue;
            }

            $seen[$id] = true;

            if ($this->failedParentHasNoActiveRetry($job)) {
                $verified[$id] = true;
            }
        }

        foreach (array_keys($duplicates) as $duplicate) {
            unset($verified[$duplicate]);
        }

        return $verified;
    }

    private function failedParentHasNoActiveRetry(object $job): bool
    {
        if (! property_exists($job, 'retried_by')) {
            return false;
        }

        $retries = $job->retried_by;

        if ($retries === null || $retries === false || $retries === '' || $retries === []) {
            return true;
        }

        if (is_string($retries)) {
            try {
                $retries = json_decode($retries, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }
        }

        if (! is_array($retries) || ! array_is_list($retries)) {
            return false;
        }

        foreach ($retries as $retry) {
            if (! is_array($retry)
                || ! is_string($retry['id'] ?? null)
                || $retry['id'] === ''
                || ! in_array($retry['status'] ?? null, ['completed', 'failed'], true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *     complete: array<int, string>,
     *     incomplete: array<int, string>,
     *     cancelled: array<int, string>
     * }
     */
    private function classifiedRepositoryIds(): array
    {
        $complete = [];
        $incomplete = [];
        $cancelled = [];

        foreach ((new RetainedBatchScanner($this->batches))->pages(self::BATCH_PAGE_SIZE) as $page) {
            foreach ($page as $batch) {
                if ($batch->cancelled()) {
                    if ($batch->pendingJobs === 0) {
                        $cancelled[] = $batch->id;
                    }

                    continue;
                }

                if ($batch->finishedAt !== null) {
                    $complete[] = $batch->id;

                    continue;
                }

                if ($this->hasOnlyFailedJobsPending($batch)) {
                    $incomplete[] = $batch->id;
                }
            }
        }

        $candidates = array_fill_keys([...$complete, ...$incomplete, ...$cancelled], true);

        if ($candidates === []) {
            return [
                'complete' => [],
                'incomplete' => [],
                'cancelled' => [],
            ];
        }

        $active = $this->activeBatchIds($candidates);

        return [
            'complete' => array_values(array_filter(
                $complete,
                static fn (string $id): bool => ! isset($active[$id]),
            )),
            'incomplete' => array_values(array_filter(
                $incomplete,
                static fn (string $id): bool => ! isset($active[$id]),
            )),
            'cancelled' => array_values(array_filter(
                $cancelled,
                static fn (string $id): bool => ! isset($active[$id]),
            )),
        ];
    }

    private function hasOnlyFailedJobsPending(Batch $batch): bool
    {
        return $batch->finishedAt === null
            && $batch->failedJobs > 0
            && $batch->pendingJobs <= $batch->failedJobs;
    }

    /**
     * @param  array<string, true>  $candidates
     * @return array<string, true>
     */
    private function activeBatchIds(array $candidates): array
    {
        $active = [];
        $sourceTotal = max(0, (int) $this->jobs->countPending());

        for ($inspected = 0; $inspected < $sourceTotal; $inspected += self::JOB_PAGE_SIZE) {
            $rawPageSize = min(self::JOB_PAGE_SIZE, $sourceTotal - $inspected);
            $page = collect($this->jobs->getPending((string) ($inspected - 1)));

            foreach ($page->take($rawPageSize) as $job) {
                if (! is_object($job)) {
                    continue;
                }

                $batchId = $this->jobData->batchId($job);

                if ($batchId !== null && isset($candidates[$batchId])) {
                    $active[$batchId] = true;
                }
            }

            if (count($active) === count($candidates)) {
                break;
            }
        }

        return $active;
    }
}
