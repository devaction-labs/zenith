<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Jobs;

enum RetainedJobType: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Silenced = 'silenced';
    case Failed = 'failed';

    public function sourceKey(): string
    {
        return "{$this->value}_jobs";
    }

    public function newestFirst(): bool
    {
        return $this === self::Completed || $this === self::Silenced;
    }

    public static function fromJobListType(JobListType $type): self
    {
        return match ($type) {
            JobListType::Pending => self::Pending,
            JobListType::Completed => self::Completed,
            JobListType::Silenced => self::Silenced,
        };
    }
}
