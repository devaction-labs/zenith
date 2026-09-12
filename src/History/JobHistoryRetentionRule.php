<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\History;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final readonly class JobHistoryRetentionRule
{
    private function __construct(
        public int $days,
        public ?string $queue,
        public ?string $jobClass,
        public ?JobHistoryStatus $status,
    ) {}

    /**
     * @param  array<array-key, mixed>  $rule
     */
    public static function tryFromArray(array $rule): ?self
    {
        $days = $rule['days'] ?? null;

        if (! is_int($days) || $days < 1) {
            return null;
        }

        $queue = $rule['queue'] ?? null;

        if ($queue !== null && (! is_string($queue) || $queue === '')) {
            return null;
        }

        $jobClass = $rule['class'] ?? null;

        if ($jobClass !== null && (! is_string($jobClass) || $jobClass === '')) {
            return null;
        }

        $statusValue = $rule['status'] ?? null;

        if ($statusValue !== null && ! is_string($statusValue)) {
            return null;
        }

        $status = $statusValue === null ? null : JobHistoryStatus::tryFrom($statusValue);

        if ($statusValue !== null && $status === null) {
            return null;
        }

        return new self(
            days: $days,
            queue: is_string($queue) ? $queue : null,
            jobClass: is_string($jobClass) ? $jobClass : null,
            status: $status,
        );
    }

    public function isWildcard(): bool
    {
        return $this->queue === null && $this->jobClass === null && $this->status === null;
    }

    public function cutoff(): Carbon
    {
        return Carbon::now()->subDays($this->days);
    }

    /**
     * @param  Builder<JobHistory>  $query
     */
    public function scopeTo(Builder $query): void
    {
        if ($this->queue !== null) {
            $query->where('queue', $this->queue);
        }

        if ($this->jobClass !== null) {
            $query->where('job_class', $this->jobClass);
        }

        if ($this->status !== null) {
            $query->where('status', $this->status->value);
        }
    }

    /**
     * @param  Builder<JobHistory>  $query
     */
    public function excludeFrom(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            if ($this->queue !== null) {
                $inner->orWhere('queue', '!=', $this->queue);
            }

            if ($this->jobClass !== null) {
                $inner->orWhere('job_class', '!=', $this->jobClass);
            }

            if ($this->status !== null) {
                $inner->orWhere('status', '!=', $this->status->value);
            }
        });
    }
}
