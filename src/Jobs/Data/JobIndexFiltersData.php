<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Jobs\Data;

use Spatie\LaravelData\Data;

final class JobIndexFiltersData extends Data
{
    public function __construct(
        public readonly ?string $job,
        public readonly ?string $queue,
        public readonly ?string $connection,
        public readonly ?string $state,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    public function hasAny(): bool
    {
        return $this->job !== null
            || $this->queue !== null
            || $this->connection !== null
            || $this->state !== null;
    }

    /** @return array<string, string|null> */
    public function signatureValues(): array
    {
        return [
            'job' => $this->job,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'state' => $this->state,
        ];
    }
}
