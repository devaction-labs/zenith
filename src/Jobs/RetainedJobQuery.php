<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs;

use DevactionLabs\Zenith\Jobs\Data\JobIndexFiltersData;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Horizon\Contracts\JobRepository;
use RuntimeException;

final readonly class RetainedJobQuery
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private JobRepository $jobs,
        private RetainedJobIndex $index,
        private ?PendingJobStateIndex $pendingStates = null,
        private ?RetainedJobCursor $cursors = null,
    ) {}

    public function page(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
        int|string|null $cursor,
        ?string $failedTag = null,
        ?string $search = null,
    ): RetainedJobQueryPage {
        $search = $this->normalizedSearch($search);
        $querySignature = $this->signature(
            $type,
            $filters,
            $failedTag,
            $search,
        );

        if ($this->shouldLeadWithReserved($type, $filters)) {
            return $this->pagePendingReservedFirst(
                type: $type,
                filters: $filters,
                cursor: $cursor,
                querySignature: $querySignature,
                search: $search,
                published: false,
            );
        }

        if ($this->shouldPageDelayedByAvailability($type, $filters)) {
            return $this->pagePendingDelayedByAvailability(
                type: $type,
                filters: $filters,
                cursor: $cursor,
                querySignature: $querySignature,
                search: $search,
                published: false,
            );
        }

        $cursors = $this->cursors ?? new RetainedJobCursor;
        $after = $cursors->decode(
            $cursor,
            $type,
            $querySignature,
        );
        $stateIds = $this->stateIds($type, $filters);
        $page = $this->index->pageIds(
            $type,
            $this->facets($type, $filters, $failedTag),
            $stateIds,
            $after,
            self::PAGE_SIZE,
            $search,
        );
        $next = $page['next'] instanceof RetainedJobPosition
            ? $cursors->encode(
                $type,
                $querySignature,
                $page['next'],
            )
            : null;

        return new RetainedJobQueryPage(
            jobs: $this->hydrate($page['ids'], $page['currentOffset']),
            total: $page['total'],
            current: $cursor === -1 || $cursor === '-1' ? null : $cursor,
            next: $next,
        );
    }

    public function pageFromPublishedIndex(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
        int|string|null $cursor,
    ): RetainedJobQueryPage {
        $querySignature = $this->signature($type, $filters);

        if ($this->shouldLeadWithReserved($type, $filters)) {
            return $this->pagePendingReservedFirst(
                type: $type,
                filters: $filters,
                cursor: $cursor,
                querySignature: $querySignature,
                search: null,
                published: true,
            );
        }

        if ($this->shouldPageDelayedByAvailability($type, $filters)) {
            return $this->pagePendingDelayedByAvailability(
                type: $type,
                filters: $filters,
                cursor: $cursor,
                querySignature: $querySignature,
                search: null,
                published: true,
            );
        }

        $cursors = $this->cursors ?? new RetainedJobCursor;
        $after = $cursors->decode(
            $cursor,
            $type,
            $querySignature,
        );
        $page = $this->index->pageIdsFromPublishedIndex(
            $type,
            $this->facets($type, $filters, null),
            $this->stateIds($type, $filters),
            $after,
            self::PAGE_SIZE,
        );
        $next = $page['next'] instanceof RetainedJobPosition
            ? $cursors->encode(
                $type,
                $querySignature,
                $page['next'],
            )
            : null;

        return new RetainedJobQueryPage(
            jobs: $this->hydrate($page['ids'], $page['currentOffset']),
            total: $page['total'],
            current: $cursor === -1 || $cursor === '-1' ? null : $cursor,
            next: $next,
        );
    }

    public function count(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
        ?float $since = null,
    ): int {
        return $this->index->count(
            $type,
            $this->facets($type, $filters, null),
            $this->stateIds($type, $filters),
            $since,
        );
    }

    /**
     * @return array{total: int, hour: int, day: int}
     */
    public function periodCounts(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
        float $hourCutoff,
        float $dayCutoff,
    ): array {
        return $this->index->periodCounts(
            $type,
            $this->facets($type, $filters, null),
            $hourCutoff,
            $dayCutoff,
        );
    }

    /**
     * @return array{total: int, hour: int, day: int}
     */
    public function periodCountsFromPublishedIndex(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
        float $hourCutoff,
        float $dayCutoff,
    ): array {
        return $this->index->periodCountsFromPublishedIndex(
            $type,
            $this->facets($type, $filters, null),
            $hourCutoff,
            $dayCutoff,
        );
    }

    public function publishedRevision(RetainedJobType $type): ?string
    {
        return $this->index->publishedRevision($type);
    }

    /**
     * @return array{total: int, headId: string|null}
     */
    public function publishedPageMetadata(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
    ): array {
        $page = $this->index->pageIdsFromPublishedIndex(
            $type,
            $this->facets($type, $filters, null),
            $this->stateIds($type, $filters),
            null,
            1,
        );

        return [
            'total' => $page['total'],
            'headId' => $page['ids'][0] ?? null,
        ];
    }

    public function refreshPublishedIndex(RetainedJobType $type): void
    {
        $this->index->synchronize($type);
    }

    public function signature(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
        ?string $failedTag = null,
        ?string $search = null,
    ): string {
        return hash('sha256', json_encode([
            'type' => $type->value,
            'filters' => $filters->signatureValues(),
            'failedTag' => $failedTag,
            'search' => $this->normalizedSearch($search),
        ], JSON_THROW_ON_ERROR));
    }

    private function shouldLeadWithReserved(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
    ): bool {
        return $type === RetainedJobType::Pending
            && $filters->state === null
            && $this->pendingStates !== null;
    }

    private function shouldPageDelayedByAvailability(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
    ): bool {
        return $type === RetainedJobType::Pending
            && $filters->state === 'delayed'
            && $this->pendingStates !== null;
    }

    private function pagePendingDelayedByAvailability(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
        int|string|null $cursor,
        string $querySignature,
        ?string $search,
        bool $published,
    ): RetainedJobQueryPage {
        $cursors = $this->cursors ?? new RetainedJobCursor;
        $after = $cursors->decode($cursor, $type, $querySignature);
        $facets = $this->facets($type, $filters, null);
        $scores = $this->delayedOrderingScores($filters, []);

        if ($scores === []) {
            return new RetainedJobQueryPage(
                jobs: new Collection,
                total: 0,
                current: $cursor === -1 || $cursor === '-1' ? null : $cursor,
                next: null,
            );
        }

        $page = $this->index->pageScoredIds(
            $type,
            $facets,
            $scores,
            [],
            $after,
            self::PAGE_SIZE,
            $search,
            $published,
        );
        $next = $page['next'] instanceof RetainedJobPosition
            ? $cursors->encode($type, $querySignature, $page['next'])
            : null;

        return new RetainedJobQueryPage(
            jobs: $this->hydrate($page['ids'], $page['currentOffset']),
            total: $page['total'],
            current: $cursor === -1 || $cursor === '-1' ? null : $cursor,
            next: $next,
        );
    }

    private function pagePendingReservedFirst(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
        int|string|null $cursor,
        string $querySignature,
        ?string $search,
        bool $published,
    ): RetainedJobQueryPage {
        $cursors = $this->cursors ?? new RetainedJobCursor;
        $cursorState = $cursors->decodeState($cursor, $type, $querySignature);
        $reservedIds = $this->reservedIds($filters);
        $facets = $this->facets($type, $filters, null);
        // Unfiltered total and rest read Horizon source directly. Unfiltered
        // reserved membership uses pageAdditionalIdsFromSource (source ZSCORE)
        // so live withCandidateKey synchronization is never required mid-request.
        $total = $this->pageIds(
            type: $type,
            facets: $facets,
            additionalIds: null,
            after: null,
            limit: 1,
            search: $search,
            published: $published,
        )['total'];

        if ($reservedIds === []) {
            $after = $cursorState?->position;
            $page = $this->pagePendingRest(
                type: $type,
                facets: $facets,
                filters: $filters,
                excludeIds: [],
                after: $after,
                limit: self::PAGE_SIZE,
                search: $search,
                published: $published,
            );
            $next = $page['next'] instanceof RetainedJobPosition
                ? $cursors->encode($type, $querySignature, $page['next'])
                : null;

            return new RetainedJobQueryPage(
                jobs: $this->hydrate($page['ids'], $page['currentOffset']),
                total: $total,
                current: $cursor === -1 || $cursor === '-1' ? null : $cursor,
                next: $next,
            );
        }

        $phase = $cursorState === null
            ? RetainedJobCursorState::PHASE_RESERVED
            : $cursorState->phase;

        if ($phase === RetainedJobCursorState::PHASE_DEFAULT) {
            $phase = RetainedJobCursorState::PHASE_REST;
        }

        if ($phase === RetainedJobCursorState::PHASE_RESERVED) {
            return $this->pageReservedLead(
                type: $type,
                facets: $facets,
                filters: $filters,
                reservedIds: $reservedIds,
                after: $cursorState?->position,
                querySignature: $querySignature,
                search: $search,
                published: $published,
                total: $total,
                currentCursor: $cursor,
            );
        }

        $rest = $this->pagePendingRest(
            type: $type,
            facets: $facets,
            filters: $filters,
            excludeIds: $reservedIds,
            after: $cursorState?->position,
            limit: self::PAGE_SIZE,
            search: $search,
            published: $published,
        );
        $next = $rest['next'] instanceof RetainedJobPosition
            ? $cursors->encodeState(
                $type,
                $querySignature,
                new RetainedJobCursorState(
                    $rest['next'],
                    RetainedJobCursorState::PHASE_REST,
                ),
            )
            : null;

        return new RetainedJobQueryPage(
            jobs: $this->hydrate($rest['ids'], $rest['currentOffset']),
            total: $total,
            current: $cursor === -1 || $cursor === '-1' ? null : $cursor,
            next: $next,
        );
    }

    /**
     * @param  array<string, string>  $facets
     * @param  array<string, true>  $reservedIds
     */
    private function pageReservedLead(
        RetainedJobType $type,
        array $facets,
        JobIndexFiltersData $filters,
        array $reservedIds,
        ?RetainedJobPosition $after,
        string $querySignature,
        ?string $search,
        bool $published,
        int $total,
        int|string|null $currentCursor,
    ): RetainedJobQueryPage {
        $cursors = $this->cursors ?? new RetainedJobCursor;
        // Live unfiltered reserved membership pages source scores only
        // (pageAdditionalIdsFromSource). Published membership is used only when
        // the caller explicitly sets $published (pageFromPublishedIndex).
        $reservedPage = $this->pageIds(
            type: $type,
            facets: $facets,
            additionalIds: $reservedIds,
            after: $after,
            limit: self::PAGE_SIZE,
            search: $search,
            published: $published,
        );
        $ids = $reservedPage['ids'];
        $currentOffset = $reservedPage['currentOffset'];
        $next = null;

        if ($reservedPage['next'] instanceof RetainedJobPosition) {
            $next = $cursors->encodeState(
                $type,
                $querySignature,
                new RetainedJobCursorState(
                    $reservedPage['next'],
                    RetainedJobCursorState::PHASE_RESERVED,
                ),
            );
        } elseif (count($ids) < self::PAGE_SIZE) {
            $fill = $this->pagePendingRest(
                type: $type,
                facets: $facets,
                filters: $filters,
                excludeIds: $reservedIds,
                after: null,
                limit: self::PAGE_SIZE - count($ids),
                search: $search,
                published: $published,
            );
            $ids = [...$ids, ...$fill['ids']];
            $next = $fill['next'] instanceof RetainedJobPosition
                ? $cursors->encodeState(
                    $type,
                    $querySignature,
                    new RetainedJobCursorState(
                        $fill['next'],
                        RetainedJobCursorState::PHASE_REST,
                    ),
                )
                : null;
        } else {
            $probe = $this->pagePendingRest(
                type: $type,
                facets: $facets,
                filters: $filters,
                excludeIds: $reservedIds,
                after: null,
                limit: 1,
                search: $search,
                published: $published,
            );

            if ($probe['ids'] !== []) {
                $next = $cursors->encodeState(
                    $type,
                    $querySignature,
                    new RetainedJobCursorState(
                        null,
                        RetainedJobCursorState::PHASE_REST,
                    ),
                );
            }
        }

        return new RetainedJobQueryPage(
            jobs: $this->hydrate($ids, $currentOffset),
            total: $total,
            current: $currentCursor === -1 || $currentCursor === '-1' ? null : $currentCursor,
            next: $next,
        );
    }

    /**
     * Two-stream exact merge for the non-reserved pending rest:
     * - non-delayed stream pages the retained source/candidate excluding delayed IDs
     * - delayed stream pages a temp set proportional only to actively delayed IDs
     * - merge at most limit+1 exact (score, id) entries under the existing reverse order
     *
     * @param  array<string, string>  $facets
     * @param  array<string, true>  $excludeIds
     * @return array{
     *     ids: array<int, string>,
     *     total: int,
     *     currentOffset: int,
     *     next: RetainedJobPosition|null
     * }
     */
    private function pagePendingRest(
        RetainedJobType $type,
        array $facets,
        JobIndexFiltersData $filters,
        array $excludeIds,
        ?RetainedJobPosition $after,
        int $limit,
        ?string $search,
        bool $published,
    ): array {
        if ($limit <= 0) {
            return [
                'ids' => [],
                'total' => 0,
                'currentOffset' => $after === null ? 0 : $after->offset,
                'next' => null,
            ];
        }

        $delayedOrderingScores = $this->delayedOrderingScores($filters, $excludeIds);

        if ($delayedOrderingScores === []) {
            return $this->pageExcluding(
                type: $type,
                facets: $facets,
                excludeIds: $excludeIds,
                after: $after,
                limit: $limit,
                search: $search,
                published: $published,
            );
        }

        $delayedIds = array_fill_keys(array_keys($delayedOrderingScores), true);
        $nonDelayedExclude = $excludeIds + $delayedIds;
        $fetch = $limit + 1;

        $nonDelayed = $this->pageExcluding(
            type: $type,
            facets: $facets,
            excludeIds: $nonDelayedExclude,
            after: $after,
            limit: $fetch,
            search: $search,
            published: $published,
        );
        $delayed = $this->index->pageScoredIds(
            $type,
            $facets,
            $delayedOrderingScores,
            $excludeIds,
            $after,
            $fetch,
            $search,
            $published,
        );

        return $this->mergePendingRestStreams(
            nonDelayedIds: $nonDelayed['ids'],
            nonDelayedScores: $this->positionsToScoreMap($nonDelayed['ids']),
            delayedIds: $delayed['ids'],
            delayedScores: $delayed['scores'],
            after: $after,
            limit: $limit,
            reverse: ! $type->newestFirst(),
        );
    }

    /**
     * @param  array<string, true>  $excludeIds
     * @return array<string, float>
     */
    private function delayedOrderingScores(
        JobIndexFiltersData $filters,
        array $excludeIds,
    ): array {
        if ($this->pendingStates === null) {
            return [];
        }

        $availability = $this->pendingStates->delayedAvailabilityScores(
            $this->pendingTargets(RetainedJobType::Pending, $filters),
        );
        $scores = [];

        foreach ($availability as $id => $availabilityAt) {
            if (isset($excludeIds[$id])) {
                continue;
            }

            // Horizon pending scores are -microtime; map availability into that space.
            $scores[$id] = -$availabilityAt;
        }

        return $scores;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, float>
     */
    private function positionsToScoreMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->index->scoresForRetainedIds(
            RetainedJobType::Pending,
            $ids,
        );
    }

    /**
     * @param  array<int, string>  $nonDelayedIds
     * @param  array<string, float>  $nonDelayedScores
     * @param  array<int, string>  $delayedIds
     * @param  array<string, float>  $delayedScores
     * @return array{
     *     ids: array<int, string>,
     *     total: int,
     *     currentOffset: int,
     *     next: RetainedJobPosition|null
     * }
     */
    private function mergePendingRestStreams(
        array $nonDelayedIds,
        array $nonDelayedScores,
        array $delayedIds,
        array $delayedScores,
        ?RetainedJobPosition $after,
        int $limit,
        bool $reverse,
    ): array {
        $pairs = [];

        foreach ($nonDelayedIds as $id) {
            if (! isset($nonDelayedScores[$id])) {
                continue;
            }

            $pairs[] = ['id' => $id, 'score' => $nonDelayedScores[$id]];
        }

        foreach ($delayedIds as $id) {
            if (! isset($delayedScores[$id])) {
                continue;
            }

            $pairs[] = ['id' => $id, 'score' => $delayedScores[$id]];
        }

        usort(
            $pairs,
            static function (array $left, array $right) use ($reverse): int {
                $scoreComparison = $left['score'] <=> $right['score'];

                if ($scoreComparison !== 0) {
                    return $reverse ? -$scoreComparison : $scoreComparison;
                }

                $idComparison = $left['id'] <=> $right['id'];

                return $reverse ? -$idComparison : $idComparison;
            },
        );

        $hasMore = count($pairs) > $limit;
        $pagePairs = array_slice($pairs, 0, $limit);
        $ids = array_column($pagePairs, 'id');
        $currentOffset = $after === null ? 0 : $after->offset;
        $last = $pagePairs === [] ? null : $pagePairs[array_key_last($pagePairs)];

        return [
            'ids' => $ids,
            'total' => 0,
            'currentOffset' => $currentOffset,
            'next' => $hasMore && $last !== null
                ? new RetainedJobPosition(
                    score: $last['score'],
                    id: $last['id'],
                    offset: $currentOffset + count($ids),
                )
                : null,
        ];
    }

    /**
     * @param  array<string, string>  $facets
     * @param  array<string, true>|null  $additionalIds
     * @return array{
     *     ids: array<int, string>,
     *     total: int,
     *     currentOffset: int,
     *     next: RetainedJobPosition|null
     * }
     */
    private function pageIds(
        RetainedJobType $type,
        array $facets,
        ?array $additionalIds,
        ?RetainedJobPosition $after,
        int $limit,
        ?string $search,
        bool $published,
    ): array {
        if ($published) {
            return $this->index->pageIdsFromPublishedIndex(
                $type,
                $facets,
                $additionalIds,
                $after,
                $limit,
            );
        }

        return $this->index->pageIds(
            $type,
            $facets,
            $additionalIds,
            $after,
            $limit,
            $search,
        );
    }

    /**
     * @param  array<string, string>  $facets
     * @param  array<string, true>  $excludeIds
     * @return array{
     *     ids: array<int, string>,
     *     total: int,
     *     currentOffset: int,
     *     next: RetainedJobPosition|null
     * }
     */
    private function pageExcluding(
        RetainedJobType $type,
        array $facets,
        array $excludeIds,
        ?RetainedJobPosition $after,
        int $limit,
        ?string $search,
        bool $published,
    ): array {
        if ($limit <= 0) {
            return [
                'ids' => [],
                'total' => 0,
                'currentOffset' => $after === null ? 0 : $after->offset,
                'next' => null,
            ];
        }

        if ($published) {
            return $this->index->pageIdsFromPublishedIndexExcluding(
                $type,
                $facets,
                null,
                $excludeIds,
                $after,
                $limit,
            );
        }

        return $this->index->pageIdsExcluding(
            $type,
            $facets,
            null,
            $excludeIds,
            $after,
            $limit,
            $search,
        );
    }

    private function normalizedSearch(?string $search): ?string
    {
        $search = trim($search ?? '');

        return $search === '' ? null : $search;
    }

    /**
     * @param  array<int, string>  $ids
     * @return Collection<int, \stdClass>
     */
    private function hydrate(array $ids, int $startingAt): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $requested = array_fill_keys($ids, true);
        $hydratedById = [];

        foreach ($this->jobs->getJobs($ids) as $job) {
            if (! is_object($job) || ! is_string($job->id ?? null)) {
                continue;
            }

            $id = $job->id;

            if (! isset($requested[$id])) {
                continue;
            }

            $values = get_object_vars($job);
            $values['index'] = $startingAt + array_search($id, $ids, true);
            $hydratedById[$id] = (object) $values;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($hydratedById[$id])) {
                $ordered[] = $hydratedById[$id];
            }
        }

        return new Collection($ordered);
    }

    /**
     * @return array<string, string>
     */
    private function facets(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
        ?string $failedTag,
    ): array {
        $facets = array_filter([
            'job' => $filters->job,
            'queue' => $filters->queue,
            'connection' => $filters->connection,
        ], is_string(...));
        $failedTag = trim($failedTag ?? '');

        if ($failedTag !== '') {
            $facets['tag'] = $failedTag;
        }

        return $facets;
    }

    /** @return array<string, true>|null */
    private function stateIds(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
    ): ?array {
        if ($filters->state === null) {
            return null;
        }

        if ($type !== RetainedJobType::Pending) {
            throw new InvalidArgumentException('Pending state can only filter pending jobs.');
        }

        if ($this->pendingStates === null) {
            throw new RuntimeException('Pending state queries are unavailable.');
        }

        return $this->pendingStates->matchingIds(
            $this->pendingTargets($type, $filters),
            $filters->state,
        );
    }

    /** @return array<string, true> */
    private function reservedIds(JobIndexFiltersData $filters): array
    {
        if ($this->pendingStates === null) {
            return [];
        }

        return $this->pendingStates->matchingIds(
            $this->pendingTargets(RetainedJobType::Pending, $filters),
            'reserved',
        );
    }

    /**
     * @return array<int, array{connection: string, queue: string}>
     */
    private function pendingTargets(
        RetainedJobType $type,
        JobIndexFiltersData $filters,
    ): array {
        $targets = [];

        foreach ($this->pendingTargetCatalogValues($type) as $encoded) {
            $target = json_decode($encoded, true);

            if (
                ! is_array($target)
                || ! is_string($target[0] ?? null)
                || ! is_string($target[1] ?? null)
            ) {
                continue;
            }

            [$connection, $queue] = $target;

            if (
                ($filters->connection !== null && $connection !== $filters->connection)
                || ($filters->queue !== null && $queue !== $filters->queue)
            ) {
                continue;
            }

            $targets[] = ['connection' => $connection, 'queue' => $queue];
        }

        return $targets;
    }

    /**
     * Prefer the published target catalog so global pending pages stay available
     * under live source churn; fall back to the live catalog when unpublished.
     *
     * @return array<int, string>
     */
    private function pendingTargetCatalogValues(RetainedJobType $type): array
    {
        try {
            return $this->index->catalogValuesFromPublishedIndex($type, 'target');
        } catch (RetainedJobIndexWarming) {
            return $this->index->catalogValues($type, 'target');
        }
    }
}
