<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits\Attributes;

use Attribute;

/**
 * Split a job class's global and rate limits by the value of one of its constructor
 * properties, so each distinct value gets its own independent limit.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Partition
{
    public function __construct(
        public string $argumentKey,
    ) {}
}
