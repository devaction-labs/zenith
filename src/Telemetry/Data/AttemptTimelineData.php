<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class AttemptTimelineData extends Data
{
    /** @param  list<JobAttemptData>  $attempts */
    public function __construct(
        public readonly bool $available,
        public readonly array $attempts,
        public readonly ?string $message,
    ) {}
}
