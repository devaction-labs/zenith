<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Batches;

final readonly class DatabaseBatchQueryRow
{
    public function __construct(
        public string $id,
        public string $name,
        public int $totalJobs,
        public int $pendingJobs,
        public int $failedJobAttempts,
        public int $pendingCount,
        public int $failedCount,
        public int $processedCount,
        public int $progress,
        public string $status,
        public int $createdAt,
        public ?int $cancelledAt,
        public ?int $finishedAt,
        public ?string $queue,
        public ?string $connection,
        public bool $queueIsExplicit,
        public bool $connectionIsExplicit,
        public bool $attributionCaptured,
        public string $sortName,
        public int $queueActivityRank,
    ) {}

    public static function from(object $row): self
    {
        $values = (array) $row;

        return new self(
            id: (string) ($values['id'] ?? ''),
            name: (string) ($values['name'] ?? ''),
            totalJobs: (int) ($values['total_jobs'] ?? 0),
            pendingJobs: (int) ($values['pending_jobs'] ?? 0),
            failedJobAttempts: (int) ($values['failed_jobs'] ?? 0),
            pendingCount: (int) ($values['pending_count'] ?? 0),
            failedCount: (int) ($values['failed_count'] ?? 0),
            processedCount: (int) ($values['processed_count'] ?? 0),
            progress: (int) ($values['progress'] ?? 0),
            status: (string) ($values['status'] ?? BatchStatus::Pending->value),
            createdAt: (int) ($values['created_at'] ?? 0),
            cancelledAt: self::nullableInt($values['cancelled_at'] ?? null),
            finishedAt: self::nullableInt($values['finished_at'] ?? null),
            queue: self::nullableString($values['queue'] ?? null),
            connection: self::nullableString($values['connection'] ?? null),
            queueIsExplicit: (bool) ($values['queue_is_explicit'] ?? false),
            connectionIsExplicit: (bool) ($values['connection_is_explicit'] ?? false),
            attributionCaptured: (bool) ($values['attribution_captured'] ?? false),
            sortName: (string) ($values['sort_name'] ?? ''),
            queueActivityRank: (int) ($values['queue_activity_rank'] ?? 0),
        );
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
