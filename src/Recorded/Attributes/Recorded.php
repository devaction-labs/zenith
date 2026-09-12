<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Recorded\Attributes;

use Attribute;

/**
 * Mark a job class so RecordJobOutput captures the return value of its
 * handle() and stores it under DevactionLabs\Zenith\Recorded\Recorded.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Recorded {}
