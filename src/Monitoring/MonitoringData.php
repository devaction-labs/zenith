<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Monitoring;

use DevactionLabs\Zenith\Jobs\Data\JobIndexFiltersData;
use DevactionLabs\Zenith\Jobs\Data\JobPageData;
use DevactionLabs\Zenith\Jobs\JobsData;
use DevactionLabs\Zenith\Jobs\RetainedJobQuery;
use DevactionLabs\Zenith\Jobs\RetainedJobType;
use DevactionLabs\Zenith\Monitoring\Data\MonitoredTagData;
use DevactionLabs\Zenith\Monitoring\Data\MonitoringPageData;
use DevactionLabs\Zenith\Monitoring\Data\MonitoringTagSummaryData;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Throwable;

final readonly class MonitoringData
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private TagRepository $tags,
        private JobRepository $jobs,
        private JobsData $jobData,
        private ?RetainedJobQuery $retainedQuery = null,
    ) {}

    public function index(): MonitoringPageData
    {
        try {
            $items = [];
            $silencedTags = $this->silencedTags();

            foreach (array_unique(array_filter($this->tags->monitoring(), is_string(...))) as $tag) {
                if ($tag === '') {
                    continue;
                }

                $trackedCount = $this->tags->count($tag);
                $failedCount = $this->tags->count("failed:{$tag}");

                $items[] = new MonitoredTagData(
                    tag: $tag,
                    trackedCount: $trackedCount,
                    failedCount: $failedCount,
                    lastActivityAt: $this->lastActivityAt($tag),
                    silenced: in_array($tag, $silencedTags, true),
                );
            }

            usort(
                $items,
                static fn (MonitoredTagData $left, MonitoredTagData $right): int => strnatcasecmp($left->tag, $right->tag),
            );

            return new MonitoringPageData(
                available: true,
                tags: $items,
                message: null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return new MonitoringPageData(
                available: false,
                tags: [],
                message: 'Monitored tags are currently unavailable.',
            );
        }
    }

    public function summary(string $tag): MonitoringTagSummaryData
    {
        try {
            return new MonitoringTagSummaryData(
                tag: $tag,
                trackedCount: $this->tags->count($tag),
                failedCount: $this->tags->count("failed:{$tag}"),
                silenced: in_array($tag, $this->silencedTags(), true),
                monitoredRetentionMinutes: $this->retentionMinutes('horizon.trim.monitored'),
                failedRetentionMinutes: $this->retentionMinutes('horizon.trim.failed'),
            );
        } catch (Throwable $exception) {
            report($exception);

            return new MonitoringTagSummaryData(
                tag: $tag,
                trackedCount: 0,
                failedCount: 0,
                silenced: in_array($tag, $this->silencedTags(), true),
                monitoredRetentionMinutes: $this->retentionMinutes('horizon.trim.monitored'),
                failedRetentionMinutes: $this->retentionMinutes('horizon.trim.failed'),
            );
        }
    }

    /** @return list<string> */
    public function monitoredTags(): array
    {
        try {
            $tags = [];

            foreach ($this->tags->monitoring() as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[] = $tag;
                }
            }

            $tags = array_values(array_unique($tags));
            sort($tags, SORT_NATURAL | SORT_FLAG_CASE);

            return $tags;
        } catch (Throwable) {
            return [];
        }
    }

    public function page(
        string $tag,
        MonitoringStatus $status,
        int|string|null $startingAt,
        ?JobIndexFiltersData $filters = null,
        ?string $search = null,
    ): JobPageData {
        $filters ??= JobIndexFiltersData::none();
        $search = is_string($search) ? trim($search) : '';
        $search = $search === '' ? null : $search;

        if ($this->retainedQuery !== null && ($filters->hasAny() || $search !== null)) {
            try {
                $type = $status === MonitoringStatus::Failed
                    ? RetainedJobType::Failed
                    : RetainedJobType::Completed;
                $page = $this->retainedQuery->page(
                    $type,
                    $filters,
                    $startingAt,
                    $tag,
                    $search,
                );

                $items = [];

                foreach ($page->jobs as $job) {
                    $row = $this->jobData->row($job);

                    if ($row !== null) {
                        $items[] = $row;
                    }
                }

                return new JobPageData(
                    available: true,
                    items: $items,
                    total: $page->total,
                    current: $page->current,
                    next: $page->next,
                    message: null,
                );
            } catch (Throwable $exception) {
                report($exception);

                return new JobPageData(
                    available: false,
                    items: [],
                    total: 0,
                    current: $startingAt,
                    next: null,
                    message: 'Exact tag search is currently unavailable.',
                );
            }
        }

        try {
            $offset = is_numeric($startingAt) ? (int) $startingAt : 0;
            $repositoryTag = $status->repositoryTag($tag);
            $references = $this->tags->paginate($repositoryTag, $offset, self::PAGE_SIZE + 1);
            $pageReferences = array_slice($references, 0, self::PAGE_SIZE);
            $jobIds = array_values(array_filter($pageReferences, is_string(...)));
            $jobs = $this->jobs->getJobs($jobIds, $offset);
            $items = [];

            foreach ($jobs as $job) {
                if (! is_object($job)) {
                    continue;
                }

                $row = $this->jobData->row($job);

                if ($row !== null) {
                    $items[] = $row;
                }
            }

            return new JobPageData(
                available: true,
                items: $items,
                total: $this->tags->count($repositoryTag),
                current: $offset,
                next: count($references) > self::PAGE_SIZE ? $offset + self::PAGE_SIZE : null,
                message: null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return new JobPageData(
                available: false,
                items: [],
                total: 0,
                current: $startingAt,
                next: null,
                message: 'Jobs for this tag are currently unavailable.',
            );
        }
    }

    private function lastActivityAt(string $tag): ?float
    {
        $recent = $this->latestActivityForTag($tag);
        $failed = $this->latestActivityForTag("failed:{$tag}");

        return match (true) {
            $recent === null => $failed,
            $failed === null => $recent,
            default => max($recent, $failed),
        };
    }

    private function latestActivityForTag(string $tag): ?float
    {
        $startingAt = 0;

        while (true) {
            $references = $this->tags->paginate($tag, $startingAt, self::PAGE_SIZE);

            if ($references === []) {
                return null;
            }

            $ids = array_values(array_filter($references, is_string(...)));
            $latest = null;

            if ($ids !== []) {
                foreach ($this->jobs->getJobs($ids) as $job) {
                    if (! is_object($job)) {
                        continue;
                    }

                    $occurredAt = $this->jobData->row($job)?->occurredAt;

                    if ($occurredAt !== null) {
                        $latest = $latest === null ? $occurredAt : max($latest, $occurredAt);
                    }
                }
            }

            if ($latest !== null || count($references) < self::PAGE_SIZE) {
                return $latest;
            }

            $startingAt += self::PAGE_SIZE;
        }
    }

    private function retentionMinutes(string $key): int
    {
        $minutes = config($key, 10080);

        return is_numeric($minutes) ? max(0, (int) $minutes) : 0;
    }

    /** @return list<string> */
    private function silencedTags(): array
    {
        $tags = config('horizon.silenced_tags', []);

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(
            $tags,
            static fn (mixed $tag): bool => is_string($tag) && $tag !== '',
        ));
    }
}
