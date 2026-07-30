<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Batches;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use NckRtl\HorizonNewDawn\Support\PollInterval;
use Throwable;

final readonly class BatchRepositoryOverview
{
    private const string CACHE_KEY = 'horizon-new-dawn:batch-repository-overview:v1';

    private const int PAGE_SIZE = 100;

    public function __construct(
        private BatchRepository $batches,
        private CacheFactory $cache,
        private ?DatabaseBatchQuery $databaseQuery = null,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     complete: bool,
     *     message: ?string,
     *     previews: list<array{id: string, name: string, progress: int}>
     * }
     */
    public function get(): array
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
                $this->build(...),
            );
            $overview = $this->normalize($payload);

            if ($overview !== null) {
                return $overview;
            }

            $cache->forget(self::CACHE_KEY);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $this->build();
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     complete: bool,
     *     message: ?string,
     *     previews: list<array{id: string, name: string, progress: int}>
     * }
     */
    private function build(): array
    {
        if ($this->databaseQuery?->supported() === true) {
            return $this->databaseQuery->overview();
        }

        $total = 0;
        $active = 0;
        /** @var list<array{id: string, name: string, progress: int}> $previews */
        $previews = [];

        foreach ((new RetainedBatchScanner($this->batches))->pages(self::PAGE_SIZE) as $page) {
            foreach ($page as $batch) {
                $total++;

                if (! $this->isActive($batch)) {
                    continue;
                }

                $active++;
                $this->considerPreview($previews, $batch);
            }
        }

        return [
            'total' => $total,
            'active' => $active,
            'complete' => true,
            'message' => null,
            'previews' => $previews,
        ];
    }

    /**
     * Keep at most the three best active previews (progress desc, then id desc).
     *
     * @param  list<array{id: string, name: string, progress: int}>  $previews
     */
    private function considerPreview(array &$previews, Batch $batch): void
    {
        $name = trim($batch->name);
        $previews[] = [
            'id' => $batch->id,
            'name' => $name === '' ? $batch->id : $name,
            'progress' => (int) round($batch->progress()),
        ];

        usort($previews, static function (array $left, array $right): int {
            $progress = $right['progress'] <=> $left['progress'];

            if ($progress !== 0) {
                return $progress;
            }

            return $right['id'] <=> $left['id'];
        });

        if (count($previews) > 3) {
            array_pop($previews);
        }
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     complete: bool,
     *     message: ?string,
     *     previews: list<array{id: string, name: string, progress: int}>
     * }|null
     */
    private function normalize(mixed $payload): ?array
    {
        if (! is_array($payload)
            || ! is_int($payload['total'] ?? null)
            || ! is_int($payload['active'] ?? null)
            || ! is_bool($payload['complete'] ?? null)
            || (! is_string($payload['message'] ?? null) && ($payload['message'] ?? null) !== null)
            || ! is_array($payload['previews'] ?? null)
        ) {
            return null;
        }

        $previews = [];

        foreach ($payload['previews'] as $preview) {
            if (! is_array($preview)
                || ! is_string($preview['id'] ?? null)
                || ! is_string($preview['name'] ?? null)
                || ! is_int($preview['progress'] ?? null)
            ) {
                return null;
            }

            $previews[] = [
                'id' => $preview['id'],
                'name' => $preview['name'],
                'progress' => $preview['progress'],
            ];
        }

        return [
            'total' => $payload['total'],
            'active' => $payload['active'],
            'complete' => $payload['complete'],
            'message' => $payload['message'] ?? null,
            'previews' => $previews,
        ];
    }

    private function isActive(Batch $batch): bool
    {
        return ! $batch->cancelled()
            && max(0, $batch->pendingJobs - $batch->failedJobs) > 0;
    }
}
