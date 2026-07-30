<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\FailedJobs\Data;

use Spatie\LaravelData\Data;

final class FailedJobBulkActionsData extends Data
{
    public function __construct(
        public readonly bool $hasFailedJobs,
        public readonly bool $retryable,
        public readonly ?string $retryUnavailableReason,
        public readonly bool $clearable,
        public readonly ?string $clearUnavailableReason,
    ) {}
}
