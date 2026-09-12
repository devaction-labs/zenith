<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Data;

use Spatie\LaravelData\Data;

final class JobAttributesData extends Data
{
    /**
     * @param  array<int>|int|null  $backoff
     */
    public function __construct(
        public readonly ?int $tries,
        public readonly array|int|null $backoff,
        public readonly ?int $timeout,
        public readonly bool $failOnTimeout,
        public readonly ?int $maxExceptions,
        public readonly ?int $uniqueFor,
        public readonly ?int $debounceFor,
        public readonly ?int $debounceMaxWait,
        public readonly ?string $queue,
        public readonly ?string $connection,
        public readonly ?int $delay,
        public readonly bool $withoutRelations,
        public readonly bool $deleteWhenMissingModels,
        public readonly ?string $routedQueue,
        public readonly ?string $routedConnection,
    ) {}

    public static function none(): self
    {
        return new self(
            tries: null,
            backoff: null,
            timeout: null,
            failOnTimeout: false,
            maxExceptions: null,
            uniqueFor: null,
            debounceFor: null,
            debounceMaxWait: null,
            queue: null,
            connection: null,
            delay: null,
            withoutRelations: false,
            deleteWhenMissingModels: false,
            routedQueue: null,
            routedConnection: null,
        );
    }
}
