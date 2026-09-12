<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Data;

use Spatie\LaravelData\Data;

final class JobFilterCatalogData extends Data
{
    /**
     * @param  array<int, array{value: string, label: string}>  $jobs
     * @param  array<int, string>  $queues
     * @param  array<int, string>  $connections
     */
    public function __construct(
        public readonly bool $available,
        public readonly array $jobs,
        public readonly array $queues,
        public readonly array $connections,
        public readonly ?string $message,
    ) {}

    public static function unavailable(
        string $message = 'Global job filters are currently unavailable.',
    ): self {
        return new self(
            available: false,
            jobs: [],
            queues: [],
            connections: [],
            message: $message,
        );
    }
}
