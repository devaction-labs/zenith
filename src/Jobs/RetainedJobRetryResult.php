<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs;

enum RetainedJobRetryResult
{
    case Retried;
    case NotRetained;
    case ClassMissing;
    case UniqueOrDebounced;
}
