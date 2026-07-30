<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Queues\Data;

use Spatie\LaravelData\Data;

final class QueueRetainedJobsData extends Data
{
    public function __construct(
        public readonly int $pending,
        public readonly bool $pendingComplete,
        public readonly ?int $completed,
        public readonly bool $completedAvailable,
        public readonly bool $completedComplete,
        public readonly ?float $completedPerMinute,
        public readonly bool $completedPerMinuteComplete,
        public readonly ?int $completedPastHour,
        public readonly bool $completedPastHourComplete,
        public readonly ?int $completedPastDay,
        public readonly bool $completedPastDayComplete,
        public readonly int $completedRetentionMinutes,
        public readonly int $failed,
        public readonly bool $failedComplete,
        public readonly float $failedPerMinute,
        public readonly bool $failedPerMinuteComplete,
        public readonly int $failedPastHour,
        public readonly bool $failedPastHourComplete,
        public readonly int $failedPastDay,
        public readonly bool $failedPastDayComplete,
        public readonly int $failedRetentionMinutes,
        public readonly int $silenced,
        public readonly bool $silencedComplete,
        public readonly ?string $message,
        public readonly bool $warming = false,
    ) {}

    public static function warming(): self
    {
        return self::emptySummary(
            warming: true,
            message: null,
        );
    }

    public static function unavailable(): self
    {
        return self::emptySummary(
            warming: false,
            message: 'Some retained job data is currently unavailable.',
        );
    }

    private static function emptySummary(bool $warming, ?string $message): self
    {
        return new self(
            pending: 0,
            pendingComplete: false,
            completed: null,
            completedAvailable: false,
            completedComplete: false,
            completedPerMinute: null,
            completedPerMinuteComplete: false,
            completedPastHour: null,
            completedPastHourComplete: false,
            completedPastDay: null,
            completedPastDayComplete: false,
            completedRetentionMinutes: max(0, (int) config('horizon.trim.completed', 60)),
            failed: 0,
            failedComplete: false,
            failedPerMinute: 0,
            failedPerMinuteComplete: false,
            failedPastHour: 0,
            failedPastHourComplete: false,
            failedPastDay: 0,
            failedPastDayComplete: false,
            failedRetentionMinutes: max(0, (int) config('horizon.trim.failed', 10080)),
            silenced: 0,
            silencedComplete: false,
            message: $message,
            warming: $warming,
        );
    }
}
