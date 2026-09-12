<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry\Data;

use Spatie\LaravelData\Data;

final class JobAttemptData extends Data
{
    public function __construct(
        public readonly int $attempt,
        public readonly string $outcome,
        public readonly ?string $exceptionClass,
        public readonly ?string $message,
        public readonly ?string $fingerprint,
        public readonly ?int $runtimeMilliseconds,
        public readonly string $node,
        public readonly int $occurredAt,
    ) {}
}
