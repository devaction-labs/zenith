<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Batches\Data;

use Spatie\LaravelData\Data;

final class BatchQueryCapabilityData extends Data
{
    public function __construct(
        public readonly bool $supported,
        public readonly ?string $message,
        public readonly bool $attributionSupported,
        public readonly ?string $attributionMessage,
    ) {}
}
