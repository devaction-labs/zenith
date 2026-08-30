<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Jobs;

use DevactionLabs\HorizonNewDawn\Jobs\Data\JobFilterCatalogData;
use DevactionLabs\HorizonNewDawn\Support\PollInterval;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Str;
use Throwable;

final readonly class RetainedJobFilterCatalog
{
    private const string CACHE_KEY_PREFIX = 'horizon-new-dawn:retained-job-filter-catalog:v1';

    public function __construct(
        private RetainedJobIndex $index,
        private ?CacheFactory $cache = null,
    ) {}

    public function for(RetainedJobType $type): JobFilterCatalogData
    {
        $cacheSeconds = PollInterval::cacheSeconds();

        if ($this->cache === null || $cacheSeconds === 0) {
            return $this->build($type);
        }

        try {
            $cache = $this->cache->store();
            $cacheKey = self::CACHE_KEY_PREFIX.":{$type->value}";
            $payload = $cache->remember(
                $cacheKey,
                $cacheSeconds,
                fn (): array => $this->build($type)->toArray(),
            );
            $catalog = $this->normalize($payload);

            if ($catalog !== null) {
                return $catalog;
            }

            $cache->forget($cacheKey);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->build($type);
    }

    private function build(RetainedJobType $type): JobFilterCatalogData
    {
        $catalogs = $this->index->catalogValuesFor(
            $type,
            ['job', 'queue', 'connection'],
        );
        $jobs = $catalogs['job'];
        $queues = $catalogs['queue'];
        $connections = $catalogs['connection'];
        natcasesort($jobs);
        natcasesort($queues);
        natcasesort($connections);
        $jobOptions = [];

        foreach ($jobs as $job) {
            $jobOptions[] = [
                'value' => $job,
                'label' => Str::afterLast($job, '\\'),
            ];
        }

        $unresolved = $this->index->unresolvedCount($type);

        return new JobFilterCatalogData(
            available: true,
            jobs: $jobOptions,
            queues: array_values($queues),
            connections: array_values($connections),
            message: $unresolved === 0
                ? null
                : sprintf(
                    '%d retained %s could not be inspected and %s excluded from exact filters.',
                    $unresolved,
                    Str::plural('job', $unresolved),
                    $unresolved === 1 ? 'is' : 'are',
                ),
        );
    }

    private function normalize(mixed $payload): ?JobFilterCatalogData
    {
        if (
            ! is_array($payload)
            || ! is_bool($payload['available'] ?? null)
            || (! is_string($payload['message'] ?? null) && ($payload['message'] ?? null) !== null)
        ) {
            return null;
        }

        $jobs = $this->normalizeJobs($payload['jobs'] ?? null);
        $queues = $this->normalizeValues($payload['queues'] ?? null);
        $connections = $this->normalizeValues($payload['connections'] ?? null);

        if ($jobs === null || $queues === null || $connections === null) {
            return null;
        }

        return new JobFilterCatalogData(
            available: $payload['available'],
            jobs: $jobs,
            queues: $queues,
            connections: $connections,
            message: $payload['message'] ?? null,
        );
    }

    /** @return list<array{value: string, label: string}>|null */
    private function normalizeJobs(mixed $jobs): ?array
    {
        if (! is_array($jobs)) {
            return null;
        }

        foreach ($jobs as $job) {
            if (
                ! is_array($job)
                || ! is_string($job['value'] ?? null)
                || ! is_string($job['label'] ?? null)
            ) {
                return null;
            }
        }

        return array_values($jobs);
    }

    /** @return list<string>|null */
    private function normalizeValues(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        foreach ($values as $value) {
            if (! is_string($value)) {
                return null;
            }
        }

        return array_values($values);
    }
}
