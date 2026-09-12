<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches;

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
            id: self::stringValue($values['id'] ?? ''),
            name: self::stringValue($values['name'] ?? ''),
            totalJobs: self::integerValue($values['total_jobs'] ?? 0),
            pendingJobs: self::integerValue($values['pending_jobs'] ?? 0),
            failedJobAttempts: self::integerValue($values['failed_jobs'] ?? 0),
            pendingCount: self::integerValue($values['pending_count'] ?? 0),
            failedCount: self::integerValue($values['failed_count'] ?? 0),
            processedCount: self::integerValue($values['processed_count'] ?? 0),
            progress: self::integerValue($values['progress'] ?? 0),
            status: self::stringValue($values['status'] ?? BatchStatus::Pending->value),
            createdAt: self::integerValue($values['created_at'] ?? 0),
            cancelledAt: self::nullableInt($values['cancelled_at'] ?? null),
            finishedAt: self::nullableInt($values['finished_at'] ?? null),
            queue: self::nullableString($values['queue'] ?? null),
            connection: self::nullableString($values['connection'] ?? null),
            queueIsExplicit: (bool) ($values['queue_is_explicit'] ?? false),
            connectionIsExplicit: (bool) ($values['connection_is_explicit'] ?? false),
            attributionCaptured: (bool) ($values['attribution_captured'] ?? false),
            sortName: self::stringValue($values['sort_name'] ?? ''),
            queueActivityRank: self::integerValue($values['queue_activity_rank'] ?? 0),
        );
    }

    private static function integerValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : self::integerValue($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
