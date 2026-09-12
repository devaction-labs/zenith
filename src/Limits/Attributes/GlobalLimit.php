<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Limits\Attributes;

use Attribute;

/**
 * Cap how many instances of the attributed job class run at once across every worker.
 *
 * Read by EnforceLimitAttributes, which applies it through the existing
 * EnforceQueueConcurrency middleware instead of implementing its own slot tracking.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class GlobalLimit
{
    public function __construct(
        public int $limit,
        public int $expiresAfter = 60,
    ) {}
}
