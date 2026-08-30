<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches;

use DevactionLabs\HorizonNewDawn\Batches\Data\BatchFilterCatalogData;
use DevactionLabs\HorizonNewDawn\Support\PollInterval;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Throwable;

final readonly class BatchFilterCatalog
{
    private const string CACHE_KEY = 'horizon-new-dawn:batch-filter-catalog:v1';

    private const int PAGE_SIZE = 100;

    public function __construct(
        private BatchRepository $batches,
        private BatchesData $data,
        private CacheFactory $cache,
        private ?DatabaseBatchQuery $databaseQuery = null,
    ) {}

    public function get(): BatchFilterCatalogData
    {
        $cacheSeconds = PollInterval::cacheSeconds();

        if ($cacheSeconds === 0) {
            return $this->build();
        }

        try {
            $cache = $this->cache->store();
            $payload = $cache->remember(
                self::CACHE_KEY,
                $cacheSeconds,
                fn (): array => $this->build()->toArray(),
            );
            $catalog = $this->normalize($payload);

            if ($catalog !== null) {
                return $catalog;
            }

            $cache->forget(self::CACHE_KEY);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->build();
    }

    private function build(): BatchFilterCatalogData
    {
        try {
            if ($this->databaseQuery !== null) {
                if ($this->databaseQuery->attributionSupported()) {
                    return $this->databaseQuery->catalog();
                }

                return new BatchFilterCatalogData(
                    available: false,
                    complete: false,
                    message: $this->databaseQuery->attributionMessage()
                        ?? 'Batch queue and connection filters are unavailable.',
                    queues: [],
                    connections: [],
                );
            }

            /** @var array<string, true> $queues */
            $queues = [];
            /** @var array<string, true> $connections */
            $connections = [];

            foreach ((new RetainedBatchScanner($this->batches))->pages(self::PAGE_SIZE) as $page) {
                foreach ($page as $batch) {
                    $queue = $this->data->queue($batch);

                    if ($queue !== '') {
                        $queues[$queue] = true;
                    }

                    $connection = $this->data->connection($batch);

                    if (is_string($connection) && $connection !== '') {
                        $connections[$connection] = true;
                    }
                }
            }

            return new BatchFilterCatalogData(
                available: true,
                complete: true,
                message: null,
                queues: $this->sortedKeys($queues),
                connections: $this->sortedKeys($connections),
            );
        } catch (Throwable $exception) {
            report($exception);

            return new BatchFilterCatalogData(
                available: false,
                complete: false,
                message: 'Batch filter options are currently unavailable.',
                queues: [],
                connections: [],
            );
        }
    }

    /**
     * @param  array<string, true>  $values
     * @return list<string>
     */
    private function sortedKeys(array $values): array
    {
        $keys = array_keys($values);
        usort($keys, static fn (string $left, string $right): int => strcasecmp($left, $right));

        return $keys;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function sortedValues(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $normalized[$value] = true;
        }

        return $this->sortedKeys($normalized);
    }

    private function normalize(mixed $payload): ?BatchFilterCatalogData
    {
        if (! is_array($payload)
            || ! is_bool($payload['available'] ?? null)
            || ! is_bool($payload['complete'] ?? null)
            || (! is_string($payload['message'] ?? null) && ($payload['message'] ?? null) !== null)
        ) {
            return null;
        }

        $queues = $this->normalizeValues($payload['queues'] ?? null);
        $connections = $this->normalizeValues($payload['connections'] ?? null);

        if ($queues === null || $connections === null) {
            return null;
        }

        return new BatchFilterCatalogData(
            available: $payload['available'],
            complete: $payload['complete'],
            message: $payload['message'] ?? null,
            queues: $queues,
            connections: $connections,
        );
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

        return $this->sortedValues($values);
    }
}
