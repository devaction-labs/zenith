<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Monitoring\Actions;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\TagRepository;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationChunkResult;
use NckRtl\HorizonNewDawn\BulkOperations\BulkOperationSnapshot;
use NckRtl\HorizonNewDawn\Monitoring\MonitoringTagGuard;
use Throwable;

final readonly class ClearRecentJobs
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private TagRepository $tags,
        private RedisFactory $redis,
        private MonitoringTagGuard $guard,
        private BulkOperationSnapshot $snapshots,
    ) {}

    public function processChunk(string $tag, ?string $operationId = null): BulkOperationChunkResult
    {
        $this->guard->ensureMonitored($this->tags, $tag);

        $operationId ??= $this->snapshots->createFromSortedSet($tag);

        $ids = $this->snapshots->nextChunk($operationId);

        if ($ids === []) {
            return BulkOperationChunkResult::completed(
                $operationId,
                $this->snapshots->finish($operationId),
            );
        }

        $connection = $this->redis->connection('horizon');

        try {
            foreach (array_chunk($ids, self::PAGE_SIZE) as $chunk) {
                $connection->command('zrem', [$tag, ...$chunk]);

                foreach ($chunk as $id) {
                    $this->snapshots->addAffected($operationId, 1);
                    $this->snapshots->acknowledge($operationId, $id);
                }
            }
        } catch (Throwable $exception) {
            $this->snapshots->renew($operationId);

            throw $exception;
        }

        $totalAffected = $this->snapshots->totalAffected($operationId);

        if ($this->snapshots->hasMore($operationId)) {
            return BulkOperationChunkResult::continuing($operationId, $totalAffected);
        }

        return BulkOperationChunkResult::completed(
            $operationId,
            $this->snapshots->finish($operationId),
        );
    }
}
