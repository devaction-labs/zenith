<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs;

/**
 * Pagination state for retained job pages.
 *
 * Phases used by pending reserved-first ordering:
 * - default: ordinary retained-score pagination
 * - reserved: live reserved membership intersected with the active query
 * - rest: remaining pending rows excluding the reserved lead set
 */
final readonly class RetainedJobCursorState
{
    public const string PHASE_DEFAULT = 'default';

    public const string PHASE_RESERVED = 'reserved';

    public const string PHASE_REST = 'rest';

    public function __construct(
        public ?RetainedJobPosition $position,
        public string $phase = self::PHASE_DEFAULT,
    ) {}
}
