<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Queues\Data;

use NckRtl\HorizonNewDawn\Batches\Data\BatchRowData;
use NckRtl\HorizonNewDawn\Jobs\Data\JobRowData;
use Spatie\LaravelData\Data;

final class QueueActivityPageData extends Data
{
    /** @param array<int, JobRowData|BatchRowData> $rows */
    public function __construct(
        public readonly bool $available,
        public readonly array $rows,
        public readonly int $total,
        public readonly bool $complete,
        public readonly string $pageName,
        public readonly int|string|null $current,
        public readonly int|string|null $next,
        public readonly ?string $message,
        public readonly bool $warming = false,
    ) {}

    public static function unavailable(string $pageName, string $message): self
    {
        return new self(
            available: false,
            rows: [],
            total: 0,
            complete: false,
            pageName: $pageName,
            current: null,
            next: null,
            message: $message,
        );
    }

    public static function warming(string $pageName): self
    {
        return new self(
            available: true,
            rows: [],
            total: 0,
            complete: false,
            pageName: $pageName,
            current: null,
            next: null,
            message: null,
            warming: true,
        );
    }
}
