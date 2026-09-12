<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Data;

final readonly class CancelPendingJobsChunkResultData
{
    public function __construct(
        public string $operationId,
        public bool $complete,
        public int $totalCancelled,
        public int $chunkCancelled,
        public int $chunkBatched,
        public int $chunkFailed,
    ) {}
}
