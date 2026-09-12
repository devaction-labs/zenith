<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Batches\Data;

use Spatie\LaravelData\Data;

final class BatchFilterCatalogData extends Data
{
    /** @param list<string> $queues
     * @param  list<string>  $connections
     */
    public function __construct(
        public readonly bool $available,
        public readonly bool $complete,
        public readonly ?string $message,
        public readonly array $queues,
        public readonly array $connections,
    ) {}
}
