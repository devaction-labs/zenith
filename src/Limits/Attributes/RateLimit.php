<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits\Attributes;

use Attribute;

/**
 * Cap how many instances of the attributed job class run within a rolling window.
 *
 * Read by EnforceLimitAttributes, which applies it through the existing
 * EnforceQueueBudget middleware instead of implementing its own rate counters.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RateLimit
{
    public function __construct(
        public int $allowed,
        public int $per,
    ) {}
}
